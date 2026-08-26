<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Event\Application\UseCases\CancelEventHandler;
use App\Modules\Event\Application\UseCases\UpdateEventHandler;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\UseCases\CancelVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\CreateEventFromConfirmedVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RescheduleBookedEventHandler;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class VenueBookingEventLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        config()->set('features.venue_rental.booking_events', true);
        CarbonImmutable::setTestNow('2026-08-26 10:00:00 Europe/Moscow');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_only_confirmed_booking_creates_one_event_and_copies_booking_projection(): void
    {
        [$user, $actor] = $this->userAndActor();
        $venue = $this->venue();
        $booking = $this->booking($venue, $user, $actor);
        $handler = app(CreateEventFromConfirmedVenueBookingHandler::class);
        $data = ['title' => 'Тренировка команды', 'type' => 'training', 'visibility' => 'private'];

        $event = $handler->handle($booking->id, $actor, $data);
        $repeat = $handler->handle($booking->id, $actor, $data);

        $this->assertSame($event->id, $repeat->id);
        $this->assertSame($booking->id, $event->booking_id);
        $this->assertSame($event->id, $booking->refresh()->event_id);
        $this->assertTrue($event->starts_at->equalTo($booking->starts_at));
        $this->assertDatabaseCount('events', 1);

        $held = $this->booking($venue, $user, $actor, VenueBookingStatusEnum::HELD, CarbonImmutable::now()->addDays(2));
        $this->expectCode('BOOKING_NOT_CONFIRMED', fn () => $handler->handle($held->id, $actor, $data));

        [$other, $otherActor] = $this->userAndActor();
        $emergencyBooking = $this->booking($venue, $other, $otherActor, startsAt: CarbonImmutable::now()->addDays(3));
        $superadmin = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => UserSystemRoleEnum::SUPERADMIN]);
        $superadminActor = app(CurrentActorResolver::class)->resolve($superadmin, null);
        $this->expectCode('EMERGENCY_REASON_REQUIRED', fn () => $handler->handle($emergencyBooking->id, $superadminActor, $data));
        $emergencyEvent = $handler->handle($emergencyBooking->id, $superadminActor, [...$data, 'emergency_reason' => 'Восстановление после сбоя.']);
        $this->assertSame('Восстановление после сбоя.', $emergencyEvent->booking_snapshot['emergency_reason']);
    }

    public function test_reschedule_updates_booking_and_event_atomically_and_rechecks_conflict(): void
    {
        [$user, $actor] = $this->userAndActor();
        $venue = $this->venue();
        $booking = $this->booking($venue, $user, $actor);
        $event = app(CreateEventFromConfirmedVenueBookingHandler::class)->handle($booking->id, $actor, ['title' => 'Игра', 'type' => 'game_training', 'visibility' => 'public']);
        $newStart = CarbonImmutable::now()->addDay()->addHours(3);

        try {
            app(UpdateEventHandler::class)->handle($event->routeIdentifier(), $actor, [
                'venue_id' => $venue->id, 'title' => $event->title, 'type' => $event->type->value,
                'visibility' => $event->visibility->value, 'starts_at' => $newStart->toIso8601String(),
                'duration_minutes' => 90, 'booking_scope' => 'whole',
            ]);
            $this->fail('Linked event interval must not be changed directly.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('через перенос брони', $exception->getMessage());
        }

        app(RescheduleBookedEventHandler::class)->handle($booking->id, $event->id, $actor, $venue->id, $newStart, 90, VenueBookingScopeEnum::WHOLE);
        app(RescheduleBookedEventHandler::class)->handle($booking->id, $event->id, $actor, $venue->id, $newStart, 90, VenueBookingScopeEnum::WHOLE);
        $this->assertTrue($booking->refresh()->starts_at->equalTo($newStart));
        $this->assertTrue($event->refresh()->starts_at->equalTo($newStart));
        $this->assertSame(2, $booking->optimistic_version);

        $this->booking($venue, $user, $actor, startsAt: CarbonImmutable::now()->addDays(2));
        $before = $booking->starts_at;
        $this->expectCode('BOOKING_CONFLICT', fn () => app(RescheduleBookedEventHandler::class)->handle(
            $booking->id, $event->id, $actor, $venue->id, CarbonImmutable::now()->addDays(2), 60, VenueBookingScopeEnum::WHOLE,
        ));
        $this->assertTrue($booking->refresh()->starts_at->equalTo($before));
        $this->assertTrue($event->refresh()->starts_at->equalTo($before));
    }

    public function test_cancelling_confirmed_booking_cancels_linked_event(): void
    {
        [$user, $actor] = $this->userAndActor();
        $venue = $this->venue();
        $booking = $this->booking($venue, $user, $actor);
        $event = app(CreateEventFromConfirmedVenueBookingHandler::class)->handle($booking->id, $actor, ['title' => 'Тренировка', 'type' => 'training', 'visibility' => 'public']);

        try {
            app(CancelEventHandler::class)->handle($event->routeIdentifier(), $actor, 'Обход через Event.');
            $this->fail('Linked event must be cancelled through booking.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('через отмену брони', $exception->getMessage());
        }

        app(CancelVenueBookingHandler::class)->handle($booking->id, $actor, 'Планы изменились.');

        $this->assertSame(VenueBookingStatusEnum::CANCELLED, $booking->refresh()->status);
        $this->assertSame(EventStatusEnum::CANCELLED, $event->refresh()->status);
        $this->assertSame('Планы изменились.', $event->cancellation_reason);
    }

    private function booking(Venue $venue, User $user, Actor $actor, VenueBookingStatusEnum $status = VenueBookingStatusEnum::CONFIRMED, ?CarbonImmutable $startsAt = null): VenueBooking
    {
        $startsAt ??= CarbonImmutable::now()->addDay();

        return VenueBooking::query()->create([
            'public_id' => (string) Str::uuid(), 'flow' => 'rental', 'venue_id' => $venue->id,
            'created_by_actor_id' => $actor->id, 'requester_user_id' => $user->id,
            'quote_snapshot' => ['policy' => ['cancellation_before_minutes' => 60, 'version' => 1], 'pricing' => ['amount_minor' => 0, 'currency' => 'RUB']],
            'status' => $status, 'scope' => VenueBookingScopeEnum::WHOLE,
            'payment_state' => VenueBookingPaymentState::NOT_REQUIRED, 'optimistic_version' => 1,
            'starts_at' => $startsAt, 'ends_at' => $startsAt->addHour(),
            'confirmed_at' => $status === VenueBookingStatusEnum::CONFIRMED ? now() : null,
            'hold_expires_at' => null, 'effective_protection_until' => null,
        ]);
    }

    private function venue(): Venue
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED, 'operational_status' => VenueOperationalStatusEnum::ACTIVE]);
        $venue->characteristics()->create(['hoops_count' => 2]);
        $venue->schedule()->create(['timezone' => 'Europe/Moscow']);

        return $venue;
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
