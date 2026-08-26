<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutboxDispatcher;
use App\Modules\VenueBooking\Application\UseCases\ApproveVenueBookingExtensionHandler;
use App\Modules\VenueBooking\Application\UseCases\CancelVenueBookingExtensionHandler;
use App\Modules\VenueBooking\Application\UseCases\CancelVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\ClaimVenueBookingPaymentHandler;
use App\Modules\VenueBooking\Application\UseCases\ConfirmVenueBookingPaymentHandler;
use App\Modules\VenueBooking\Application\UseCases\OpenVenueBookingPaymentWindowHandler;
use App\Modules\VenueBooking\Application\UseCases\RejectVenueBookingExtensionHandler;
use App\Modules\VenueBooking\Application\UseCases\RequestVenueBookingExtensionHandler;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingExtensionStatus;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Events\VenueBookingPaymentConfirmed;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingOutboxMessage;
use App\Modules\VenueBooking\Infrastructure\Jobs\ExpireVenueBookingIfDueJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VenueBookingExtensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_request_does_not_change_deadline_and_policy_limit_is_enforced(): void
    {
        [, , $venue] = $this->ownedVenue();
        [$applicant, $actor] = $this->userAndActor();
        $booking = $this->heldBooking($venue, $applicant, $actor);

        $extension = app(RequestVenueBookingExtensionHandler::class)->handle(
            $booking->id,
            $actor,
            $booking->effective_protection_until->addMinutes(20),
            'Нужно завершить согласование состава.',
        );

        $this->assertSame(VenueBookingExtensionStatus::PENDING, $extension->status);
        $this->assertTrue($booking->effective_protection_until->equalTo($booking->refresh()->effective_protection_until));
        $this->assertSame(1, $booking->optimistic_version);

        app(CancelVenueBookingExtensionHandler::class)->handle($booking->id, $extension->id, $actor);
        $this->expectCode('EXTENSION_LIMIT_EXCEEDED', fn () => app(RequestVenueBookingExtensionHandler::class)->handle(
            $booking->id,
            $actor,
            $booking->hold_expires_at->addMinutes(31),
            'Слишком долгий срок.',
        ));
    }

    public function test_approve_is_atomic_repeat_safe_and_makes_old_expiry_job_stale(): void
    {
        Queue::fake();
        [, $ownerActor, $venue] = $this->ownedVenue();
        [$applicant, $applicantActor] = $this->userAndActor();
        $booking = $this->heldBooking($venue, $applicant, $applicantActor);
        $oldJob = new ExpireVenueBookingIfDueJob($booking->id, 1, $booking->effective_protection_until->toIso8601String());
        $requestedUntil = $booking->effective_protection_until->addMinutes(20);
        $extension = app(RequestVenueBookingExtensionHandler::class)->handle($booking->id, $applicantActor, $requestedUntil, 'Нужно больше времени.');

        $handler = app(ApproveVenueBookingExtensionHandler::class);
        $handler->handle($booking->id, $extension->id, $ownerActor, 'Согласовано.');
        $handler->handle($booking->id, $extension->id, $ownerActor, 'Повтор доставки команды.');

        $booking->refresh();
        $this->assertTrue($booking->effective_protection_until->equalTo($requestedUntil));
        $this->assertSame(2, $booking->optimistic_version);
        $this->assertSame(VenueBookingExtensionStatus::APPROVED, $extension->refresh()->status);

        CarbonImmutable::setTestNow($extension->previous_deadline_at->addSecond());
        app()->call([$oldJob, 'handle']);
        $this->assertSame(VenueBookingStatusEnum::HELD, $booking->refresh()->status);
    }

    public function test_new_conflict_prevents_approval_without_changing_request_or_deadline(): void
    {
        [, $ownerActor, $venue] = $this->ownedVenue();
        [$applicant, $applicantActor] = $this->userAndActor();
        $booking = $this->heldBooking($venue, $applicant, $applicantActor);
        $extension = app(RequestVenueBookingExtensionHandler::class)->handle(
            $booking->id,
            $applicantActor,
            $booking->effective_protection_until->addMinutes(10),
            'Ожидаем подтверждение.',
        );
        [$other, $otherActor] = $this->userAndActor();
        $this->heldBooking($venue, $other, $otherActor, startsAt: $booking->starts_at->addMinutes(15));

        $this->expectCode('BOOKING_CONFLICT', fn () => app(ApproveVenueBookingExtensionHandler::class)
            ->handle($booking->id, $extension->id, $ownerActor));

        $this->assertSame(VenueBookingExtensionStatus::PENDING, $extension->refresh()->status);
        $this->assertSame(1, $booking->refresh()->optimistic_version);
    }

    public function test_commercial_side_rejects_and_only_requester_cancels(): void
    {
        [, $ownerActor, $venue] = $this->ownedVenue();
        [$applicant, $applicantActor] = $this->userAndActor();
        [, $outsiderActor] = $this->userAndActor();
        $booking = $this->heldBooking($venue, $applicant, $applicantActor);
        $request = app(RequestVenueBookingExtensionHandler::class)->handle($booking->id, $applicantActor, $booking->effective_protection_until->addMinutes(10), 'Причина.');

        $this->expectCode('BOOKING_FORBIDDEN', fn () => app(CancelVenueBookingExtensionHandler::class)->handle($booking->id, $request->id, $outsiderActor));
        app(RejectVenueBookingExtensionHandler::class)->handle($booking->id, $request->id, $ownerActor, 'Не можем продлить.');

        $this->assertSame(VenueBookingExtensionStatus::REJECTED, $request->refresh()->status);
        $this->assertTrue($booking->effective_protection_until->equalTo($booking->refresh()->effective_protection_until));
    }

    public function test_payment_claim_does_not_confirm_booking_and_double_claim_is_safe(): void
    {
        Queue::fake();
        [, $ownerActor, $venue] = $this->ownedVenue();
        [$applicant, $applicantActor] = $this->userAndActor();
        $booking = $this->paymentBooking($venue, $applicant, $applicantActor);
        $attempt = app(OpenVenueBookingPaymentWindowHandler::class)->handle($booking->id, $ownerActor, 'bank_transfer', 'Перевод по реквизитам.');

        $this->assertSame(12500, $attempt->amount_minor);
        $this->assertSame('RUB', $attempt->currency);
        $this->assertTrue($booking->refresh()->effective_protection_until->equalTo($attempt->window_expires_at));

        $handler = app(ClaimVenueBookingPaymentHandler::class);
        $handler->handle($booking->id, $attempt->id, $applicantActor, ['reference' => 'receipt-42']);
        $version = $booking->refresh()->optimistic_version;
        $handler->handle($booking->id, $attempt->id, $applicantActor, ['reference' => 'duplicate']);

        $this->assertSame($version, $booking->refresh()->optimistic_version);
        $this->assertSame(VenueBookingPaymentState::CLAIMED, $booking->payment_state);
        $this->assertSame(VenueBookingStatusEnum::HELD, $booking->status);
    }

    public function test_payment_confirmation_uses_quote_amount_and_only_unlocks_booking_confirmation(): void
    {
        Queue::fake();
        [, $ownerActor, $venue] = $this->ownedVenue();
        [$applicant, $applicantActor] = $this->userAndActor();
        $booking = $this->paymentBooking($venue, $applicant, $applicantActor);
        $attempt = app(OpenVenueBookingPaymentWindowHandler::class)->handle($booking->id, $ownerActor, 'cash', 'В кассе площадки.');
        app(ClaimVenueBookingPaymentHandler::class)->handle($booking->id, $attempt->id, $applicantActor, []);

        $attempt->update(['amount_minor' => 1]);
        $this->expectCode('INVALID_PAYMENT_AMOUNT', fn () => app(ConfirmVenueBookingPaymentHandler::class)->handle($booking->id, $attempt->id, $ownerActor));
        $attempt->update(['amount_minor' => 12500]);
        app(ConfirmVenueBookingPaymentHandler::class)->handle($booking->id, $attempt->id, $ownerActor);

        $this->assertSame(VenueBookingPaymentState::CONFIRMED, $booking->refresh()->payment_state);
        $this->assertSame(VenueBookingStatusEnum::HELD, $booking->status);
        $message = VenueBookingOutboxMessage::query()->where('event_type', VenueBookingPaymentConfirmed::class)->firstOrFail();
        Event::fake([VenueBookingPaymentConfirmed::class]);
        app(VenueBookingOutboxDispatcher::class)->dispatch($message->id);
        Event::assertDispatched(VenueBookingPaymentConfirmed::class, fn (VenueBookingPaymentConfirmed $event): bool => $event->paymentAttemptId === $attempt->id && $event->messageId === $message->message_id);
    }

    public function test_payment_window_expires_once_and_parallel_cancel_wins_over_review(): void
    {
        Queue::fake();
        [, $ownerActor, $venue] = $this->ownedVenue();
        [$applicant, $applicantActor] = $this->userAndActor();
        $booking = $this->paymentBooking($venue, $applicant, $applicantActor);
        $attempt = app(OpenVenueBookingPaymentWindowHandler::class)->handle($booking->id, $ownerActor, 'other', 'Внешняя оплата.');
        app(ClaimVenueBookingPaymentHandler::class)->handle($booking->id, $attempt->id, $applicantActor, []);
        $booking->refresh();
        $job = new ExpireVenueBookingIfDueJob($booking->id, $booking->optimistic_version, $booking->effective_protection_until->toIso8601String());
        CarbonImmutable::setTestNow($booking->effective_protection_until);
        $this->expectCode('PAYMENT_WINDOW_EXPIRED', fn () => app(ConfirmVenueBookingPaymentHandler::class)->handle($booking->id, $attempt->id, $ownerActor));
        CarbonImmutable::setTestNow($booking->effective_protection_until->addSecond());
        app()->call([$job, 'handle']);

        $this->assertSame(VenueBookingStatusEnum::EXPIRED, $booking->refresh()->status);
        $this->assertSame(VenueBookingPaymentState::EXPIRED, $attempt->refresh()->status);
        $this->expectCode('PAYMENT_CONFIRM_UNAVAILABLE', fn () => app(ConfirmVenueBookingPaymentHandler::class)->handle($booking->id, $attempt->id, $ownerActor));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Europe/Moscow'));
        [$secondApplicant, $secondActor] = $this->userAndActor();
        $cancelled = $this->paymentBooking($venue, $secondApplicant, $secondActor);
        $cancelledAttempt = app(OpenVenueBookingPaymentWindowHandler::class)->handle($cancelled->id, $ownerActor, 'other', 'Внешняя оплата.');
        app(ClaimVenueBookingPaymentHandler::class)->handle($cancelled->id, $cancelledAttempt->id, $secondActor, []);
        app(CancelVenueBookingHandler::class)->handle($cancelled->id, $secondActor, 'Отмена заявителем.');

        $this->assertSame(VenueBookingStatusEnum::CANCELLED, $cancelled->refresh()->status);
        $this->assertSame(VenueBookingPaymentState::EXPIRED, $cancelledAttempt->refresh()->status);
        $this->expectCode('PAYMENT_CONFIRM_UNAVAILABLE', fn () => app(ConfirmVenueBookingPaymentHandler::class)->handle($cancelled->id, $cancelledAttempt->id, $ownerActor));
    }

    private function heldBooking(Venue $venue, User $requester, Actor $actor, ?CarbonImmutable $startsAt = null): VenueBooking
    {
        $startsAt ??= CarbonImmutable::now()->addDay();
        $deadline = CarbonImmutable::now()->addMinutes(30);

        return VenueBooking::query()->create([
            'public_id' => (string) Str::uuid(),
            'flow' => 'rental',
            'venue_id' => $venue->id,
            'created_by_actor_id' => $actor->id,
            'requester_user_id' => $requester->id,
            'quote_snapshot' => ['policy' => [
                'allows_hold_extension' => true,
                'maximum_hold_extension_minutes' => 30,
                'time_step_minutes' => 30,
            ]],
            'status' => VenueBookingStatusEnum::HELD,
            'scope' => VenueBookingScopeEnum::WHOLE,
            'payment_state' => VenueBookingPaymentState::NOT_REQUIRED,
            'optimistic_version' => 1,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'hold_expires_at' => $deadline,
            'effective_protection_until' => $deadline,
            'held_at' => now(),
        ]);
    }

    private function paymentBooking(Venue $venue, User $requester, Actor $actor): VenueBooking
    {
        $booking = $this->heldBooking($venue, $requester, $actor);
        $booking->update([
            'quote_snapshot' => ['policy' => [
                'requires_payment' => true,
                'payment_window_minutes' => 45,
                'hold_duration_minutes' => 30,
                'time_step_minutes' => 30,
            ], 'pricing' => ['amount_minor' => 12500, 'currency' => 'RUB']],
            'payment_state' => VenueBookingPaymentState::NOT_STARTED,
        ]);

        return $booking->refresh();
    }

    /** @return array{User, Actor, Venue} */
    private function ownedVenue(): array
    {
        [$owner, $actor] = $this->userAndActor();
        $venue = Venue::factory()->create();
        $venue->characteristics()->create(['hoops_count' => 2]);
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'status' => ContractStatusEnum::ACTIVE,
            'starts_at' => now()->subMinute(),
            'assigner' => UserParticipationRoleAssignerEnum::OTHER,
        ]);
        $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::VENUE,
            'scope_id' => $venue->id,
            'user_id' => $owner->id,
            'access_level' => VenueMembershipAccessLevelEnum::OWNER,
        ]);
        $contract->permissions()->createMany(array_map(
            static fn (VenuePermissionEnum $permission): array => ['permission' => $permission->value],
            VenueMembershipAccessLevelEnum::OWNER->defaultPermissions(),
        ));

        return [$owner, $actor, $venue];
    }

    /** @return array{User, Actor} */
    private function userAndActor(): array
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        return [$user, app(CurrentActorResolver::class)->resolve($user, null)];
    }

    private function expectCode(string $code, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected {$code}.");
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }
}
