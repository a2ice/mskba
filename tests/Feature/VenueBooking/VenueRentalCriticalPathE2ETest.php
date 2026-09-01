<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\UseCases\AcceptVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\ClaimVenueBookingPaymentHandler;
use App\Modules\VenueBooking\Application\UseCases\ConfirmVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\ConfirmVenueBookingPaymentHandler;
use App\Modules\VenueBooking\Application\UseCases\CreateEventFromConfirmedVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\OpenVenueBookingPaymentWindowHandler;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VenueRentalCriticalPathE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_request_hold_manual_payment_confirmation_booking_confirmation_and_event_creation(): void
    {
        config()->set('features.venue_rental.rental_flow', true);
        config()->set('features.venue_rental.external_payment', true);
        config()->set('features.venue_rental.payment_port', true);
        config()->set('features.venue_rental.booking_events', true);
        Carbon::setTestNow('2026-08-26 10:00:00 Europe/Moscow');
        Queue::fake();

        $requester = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $requesterActor = app(CurrentActorResolver::class)->resolve($requester, null);
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => UserSystemRoleEnum::SUPERADMIN]);
        $ownerActor = app(CurrentActorResolver::class)->resolve($owner, null);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED, 'operational_status' => VenueOperationalStatusEnum::ACTIVE]);
        $venue->characteristics()->create(['hoops_count' => 2]);
        $venue->schedule()->create(['timezone' => 'Europe/Moscow']);
        $startsAt = CarbonImmutable::now()->addDay();
        $booking = VenueBooking::query()->create([
            'public_id' => (string) Str::uuid(), 'flow' => 'rental', 'venue_id' => $venue->id,
            'created_by_actor_id' => $requesterActor->id, 'requester_user_id' => $requester->id,
            'quote_snapshot' => [
                'policy' => ['hold_duration_minutes' => 30, 'payment_window_minutes' => 20, 'requires_payment' => true, 'cancellation_before_minutes' => 60],
                'pricing' => ['amount_minor' => 12500, 'currency' => 'RUB'],
            ],
            'status' => VenueBookingStatusEnum::REQUESTED, 'scope' => VenueBookingScopeEnum::WHOLE,
            'payment_state' => VenueBookingPaymentState::NOT_STARTED, 'optimistic_version' => 1,
            'starts_at' => $startsAt, 'ends_at' => $startsAt->addHour(), 'requested_at' => now(),
        ]);

        $booking = app(AcceptVenueBookingHandler::class)->handle($booking->id, $ownerActor, 1, (string) Str::uuid());
        $attempt = app(OpenVenueBookingPaymentWindowHandler::class)->handle($booking->id, $ownerActor, 'bank_transfer', 'Перевод по реквизитам площадки.');
        $attempt = app(ClaimVenueBookingPaymentHandler::class)->handle($booking->id, $attempt->id, $requesterActor, ['reference' => 'receipt-42']);
        $attempt = app(ConfirmVenueBookingPaymentHandler::class)->handle($booking->id, $attempt->id, $ownerActor, 'Поступление проверено.');
        $booking = app(ConfirmVenueBookingHandler::class)->handle($booking->id, $ownerActor, $booking->refresh()->optimistic_version, (string) Str::uuid());
        $event = app(CreateEventFromConfirmedVenueBookingHandler::class)->handle($booking->id, $requesterActor, ['title' => 'Тренировка после аренды', 'type' => 'training', 'visibility' => 'private']);

        $this->assertSame(VenueBookingPaymentState::CONFIRMED, $attempt->status);
        $this->assertSame(VenueBookingStatusEnum::CONFIRMED, $booking->status);
        $this->assertSame($booking->id, $event->booking_id);
        $this->assertSame($event->id, $booking->refresh()->event_id);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $attempt->id, 'event' => 'manual_payment_confirmed']);
        $this->assertDatabaseCount('events', 1);

        Carbon::setTestNow();
    }
}
