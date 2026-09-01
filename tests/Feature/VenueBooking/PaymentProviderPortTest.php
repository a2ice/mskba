<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Payments\CreatePaymentIntentData;
use App\Modules\VenueBooking\Application\Payments\PaymentProviderIntent;
use App\Modules\VenueBooking\Application\Payments\PaymentProviderPort;
use App\Modules\VenueBooking\Application\Payments\VerifiedPaymentEvent;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutbox;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Exceptions\InvalidPaymentWebhookException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPaymentAttempt;
use App\Modules\VenueBooking\Infrastructure\Jobs\ReconcilePaymentIntentJob;
use App\Modules\VenueBooking\Infrastructure\Payments\ExternalManualPaymentAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaymentProviderPortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        config()->set('features.venue_rental.external_payment', true);
        config()->set('features.venue_rental.payment_port', true);
        Carbon::setTestNow('2026-08-26 10:00:00 Europe/Moscow');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manual_adapter_contract_is_idempotent_and_never_accepts_webhooks(): void
    {
        $adapter = new ExternalManualPaymentAdapter;
        $data = new CreatePaymentIntentData((string) Str::uuid(), 12500, 'RUB', 'mskba', (string) Str::uuid(), CarbonImmutable::now()->addHour());

        $first = $adapter->createIntent($data);
        $repeat = $adapter->createIntent($data);

        $this->assertSame('external_manual', $adapter->name());
        $this->assertSame($first->reference, $repeat->reference);
        $this->assertSame(VenueBookingPaymentState::WINDOW_OPEN, $first->status);
        $this->assertSame(VenueBookingPaymentState::EXPIRED, $adapter->cancelIntent($first->reference)->status);
        $this->expectException(InvalidPaymentWebhookException::class);
        $adapter->verifyWebhook([], []);
    }

    public function test_verified_webhook_is_idempotent_and_out_of_order_failure_cannot_revert_confirmation(): void
    {
        [$booking, $attempt] = $this->bookingAndAttempt();
        $fake = new FakePaymentProvider;
        $fake->event = $this->event($booking, $attempt, 'evt-1', VenueBookingPaymentState::CONFIRMED);
        $this->app->instance(PaymentProviderPort::class, $fake);
        $payload = ['event_id' => 'evt-1', 'card_number' => '4111111111111111', 'provider_secret' => 'never-store'];

        $this->withHeader('X-Signature', 'valid')->postJson(route('integrations.venue-rental-payments.webhook', 'fakepay'), $payload)
            ->assertAccepted()->assertJsonPath('status', 'processed');
        $this->withHeader('X-Signature', 'valid')->postJson(route('integrations.venue-rental-payments.webhook', 'fakepay'), $payload)
            ->assertAccepted()->assertJsonPath('status', 'processed');

        $this->assertSame(VenueBookingPaymentState::CONFIRMED, $attempt->refresh()->status);
        $this->assertSame(VenueBookingPaymentState::CONFIRMED, $booking->refresh()->payment_state);
        $this->assertSame(VenueBookingStatusEnum::HELD, $booking->status);
        $this->assertSame(2, $booking->optimistic_version);

        $fake->event = $this->event($booking, $attempt, 'evt-2', VenueBookingPaymentState::REJECTED);
        $this->withHeader('X-Signature', 'valid')->postJson(route('integrations.venue-rental-payments.webhook', 'fakepay'), ['event_id' => 'evt-2'])->assertAccepted();
        $this->assertSame(VenueBookingPaymentState::CONFIRMED, $attempt->refresh()->status);
        $this->assertSame(2, $booking->refresh()->optimistic_version);

        $stored = (string) \DB::table('venue_booking_payment_webhooks')->where('provider_event_id', 'evt-1')->value('safe_payload');
        $this->assertStringNotContainsString('411111', $stored);
        $this->assertStringNotContainsString('never-store', $stored);
    }

    public function test_invalid_signature_and_mismatched_amount_are_rejected_without_confirming(): void
    {
        [$booking, $attempt] = $this->bookingAndAttempt();
        $fake = new FakePaymentProvider;
        $fake->event = $this->event($booking, $attempt, 'bad-signature', VenueBookingPaymentState::CONFIRMED);
        $this->app->instance(PaymentProviderPort::class, $fake);

        $this->postJson(route('integrations.venue-rental-payments.webhook', 'fakepay'), ['event_id' => 'bad-signature'])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_PAYMENT_WEBHOOK');
        $this->assertDatabaseHas('venue_booking_payment_webhooks', ['provider_event_id' => 'bad-signature', 'signature_valid' => false, 'status' => 'rejected']);

        $fake->event = new VerifiedPaymentEvent('wrong-amount', $attempt->provider_reference, $booking->public_id, 1, 'RUB', 'mskba', VenueBookingPaymentState::CONFIRMED);
        $this->withHeader('X-Signature', 'valid')->postJson(route('integrations.venue-rental-payments.webhook', 'fakepay'), ['event_id' => 'wrong-amount'])
            ->assertStatus(422);
        $this->assertDatabaseHas('venue_booking_payment_webhooks', ['provider_event_id' => 'wrong-amount', 'status' => 'failed']);
        $this->assertSame(VenueBookingPaymentState::WINDOW_OPEN, $attempt->refresh()->status);
        $this->assertSame(1, $booking->refresh()->optimistic_version);
    }

    public function test_reconciliation_retry_confirms_once_under_the_same_aggregate_lock(): void
    {
        [$booking, $attempt] = $this->bookingAndAttempt();
        $fake = new FakePaymentProvider;
        $fake->event = $this->event($booking, $attempt, 'reconcile', VenueBookingPaymentState::CONFIRMED);
        $job = new ReconcilePaymentIntentJob($attempt->id);

        $job->handle($fake, app(LockedVenueBooking::class), app(VenueBookingOutbox::class));
        $job->handle($fake, app(LockedVenueBooking::class), app(VenueBookingOutbox::class));

        $this->assertSame(VenueBookingPaymentState::CONFIRMED, $attempt->refresh()->status);
        $this->assertSame(VenueBookingPaymentState::CONFIRMED, $booking->refresh()->payment_state);
        $this->assertSame(2, $booking->optimistic_version);
    }

    public function test_webhook_rejects_wrong_currency_merchant_and_booking_reference(): void
    {
        [$booking, $attempt] = $this->bookingAndAttempt();
        $fake = new FakePaymentProvider;
        $this->app->instance(PaymentProviderPort::class, $fake);
        $invalidEvents = [
            new VerifiedPaymentEvent('wrong-currency', $attempt->provider_reference, $booking->public_id, 12500, 'USD', 'mskba', VenueBookingPaymentState::CONFIRMED),
            new VerifiedPaymentEvent('wrong-merchant', $attempt->provider_reference, $booking->public_id, 12500, 'RUB', 'other', VenueBookingPaymentState::CONFIRMED),
            new VerifiedPaymentEvent('wrong-booking', $attempt->provider_reference, (string) Str::uuid(), 12500, 'RUB', 'mskba', VenueBookingPaymentState::CONFIRMED),
        ];

        foreach ($invalidEvents as $event) {
            $fake->event = $event;
            $this->withHeader('X-Signature', 'valid')
                ->postJson(route('integrations.venue-rental-payments.webhook', 'fakepay'), ['event_id' => $event->eventId])
                ->assertStatus(422);
            $this->assertDatabaseHas('venue_booking_payment_webhooks', ['provider_event_id' => $event->eventId, 'status' => 'failed']);
        }
        $this->assertSame(VenueBookingPaymentState::WINDOW_OPEN, $attempt->refresh()->status);
    }

    public function test_late_verified_confirmation_is_recorded_without_confirming_terminal_booking(): void
    {
        [$booking, $attempt] = $this->bookingAndAttempt();
        $attempt->update(['window_expires_at' => now()->subMinute()]);
        $booking->applyLifecycleTransition(['status' => VenueBookingStatusEnum::EXPIRED, 'terminal_at' => now()]);
        $fake = new FakePaymentProvider;
        $fake->event = $this->event($booking, $attempt, 'late-confirmation', VenueBookingPaymentState::CONFIRMED);
        $this->app->instance(PaymentProviderPort::class, $fake);

        $this->withHeader('X-Signature', 'valid')
            ->postJson(route('integrations.venue-rental-payments.webhook', 'fakepay'), ['event_id' => 'late-confirmation'])
            ->assertAccepted();

        $this->assertSame(VenueBookingPaymentState::CONFIRMED, $attempt->refresh()->status);
        $this->assertSame(VenueBookingStatusEnum::EXPIRED, $booking->refresh()->status);
    }

    /** @return array{VenueBooking, VenueBookingPaymentAttempt} */
    private function bookingAndAttempt(): array
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $actor = app(CurrentActorResolver::class)->resolve($user, null);
        $venue = Venue::factory()->create();
        $startsAt = CarbonImmutable::now()->addDay();
        $booking = VenueBooking::query()->create([
            'public_id' => (string) Str::uuid(), 'flow' => 'rental', 'venue_id' => $venue->id,
            'created_by_actor_id' => $actor->id, 'requester_user_id' => $user->id,
            'quote_snapshot' => ['pricing' => ['amount_minor' => 12500, 'currency' => 'RUB']],
            'status' => VenueBookingStatusEnum::HELD, 'scope' => VenueBookingScopeEnum::WHOLE,
            'payment_state' => VenueBookingPaymentState::WINDOW_OPEN, 'optimistic_version' => 1,
            'starts_at' => $startsAt, 'ends_at' => $startsAt->addHour(),
            'hold_expires_at' => now()->addHour(), 'effective_protection_until' => now()->addHour(),
        ]);
        $attempt = VenueBookingPaymentAttempt::query()->create([
            'public_id' => (string) Str::uuid(), 'venue_booking_id' => $booking->id,
            'amount_minor' => 12500, 'currency' => 'RUB', 'method' => 'provider',
            'provider' => 'fakepay', 'provider_reference' => 'intent-1',
            'provider_idempotency_key' => (string) Str::uuid(), 'merchant_reference' => 'mskba',
            'payment_instructions' => 'Provider redirect', 'status' => VenueBookingPaymentState::WINDOW_OPEN,
            'window_opened_at' => now(), 'window_expires_at' => now()->addHour(), 'opened_by_actor_id' => $actor->id,
        ]);

        return [$booking, $attempt];
    }

    private function event(VenueBooking $booking, VenueBookingPaymentAttempt $attempt, string $eventId, VenueBookingPaymentState $status): VerifiedPaymentEvent
    {
        return new VerifiedPaymentEvent($eventId, $attempt->provider_reference, $booking->public_id, 12500, 'RUB', 'mskba', $status, ['type' => $status->value]);
    }
}

final class FakePaymentProvider implements PaymentProviderPort
{
    public VerifiedPaymentEvent $event;

    public function name(): string
    {
        return 'fakepay';
    }

    public function createIntent(CreatePaymentIntentData $data): PaymentProviderIntent
    {
        return new PaymentProviderIntent('intent-1', VenueBookingPaymentState::WINDOW_OPEN);
    }

    public function queryStatus(string $intentReference): PaymentProviderIntent
    {
        return new PaymentProviderIntent($intentReference, $this->event->status);
    }

    public function cancelIntent(string $intentReference): PaymentProviderIntent
    {
        return new PaymentProviderIntent($intentReference, VenueBookingPaymentState::EXPIRED);
    }

    public function verifyWebhook(array $payload, array $headers): VerifiedPaymentEvent
    {
        if (($headers['x-signature'] ?? null) !== 'valid') {
            throw new InvalidPaymentWebhookException('Подпись webhook недействительна.');
        }

        return $this->event;
    }
}
