<?php

namespace Tests\Feature\Coordination;

use App\Modules\Coordination\Application\UseCases\CloseExpiredVenueBookingAttendanceRoundsHandler;
use App\Modules\Coordination\Application\UseCases\CloseVenueBookingAttendanceRoundHandler;
use App\Modules\Coordination\Application\UseCases\OpenVenueBookingAttendanceRoundHandler;
use App\Modules\Coordination\Application\UseCases\RespondVenueBookingAttendanceRoundHandler;
use App\Modules\Coordination\Domain\Enums\VenueBookingAttendanceResponseValue;
use App\Modules\Coordination\Domain\Enums\VenueBookingAttendanceRoundStatus;
use App\Modules\Coordination\Domain\Events\VenueBookingAttendanceResponded;
use App\Modules\Coordination\Domain\Events\VenueBookingAttendanceRoundClosed;
use App\Modules\Coordination\Domain\Events\VenueBookingAttendanceThresholdReached;
use App\Modules\Coordination\Domain\Exceptions\VenueBookingAttendanceException;
use App\Modules\Coordination\Infrastructure\Listeners\CloseAttendanceRoundAfterBookingEnds;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Events\VenueBookingConfirmed;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VenueBookingAttendanceRoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.attendance_v2', true);
        Carbon::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_round_deadline_is_clipped_to_hold_and_does_not_extend_booking(): void
    {
        [$booking, $requesterActor] = $this->heldBooking();
        $invitees = User::factory()->count(2)->create(['status' => UserStatusEnum::CONFIRMED]);
        $protectionBefore = $booking->effective_protection_until;

        $round = app(OpenVenueBookingAttendanceRoundHandler::class)->handle(
            $booking->id,
            $requesterActor,
            CarbonImmutable::now()->addHours(2),
            $invitees->modelKeys(),
            2,
        );

        $this->assertTrue($round->deadline_at->equalTo($protectionBefore));
        $this->assertSame(2, $round->pending_count);
        $this->assertSame(VenueBookingStatusEnum::HELD, $booking->refresh()->status);
        $this->assertTrue($booking->effective_protection_until->equalTo($protectionBefore));
        $this->assertDatabaseCount('user_notifications', 2);
    }

    public function test_round_requires_active_hold_and_requester_permissions(): void
    {
        [$booking, $requesterActor] = $this->heldBooking();
        [, $strangerActor] = $this->userAndActor();
        [$invitee] = $this->userAndActor();

        try {
            app(OpenVenueBookingAttendanceRoundHandler::class)->handle(
                $booking->id,
                $strangerActor,
                CarbonImmutable::now()->addMinutes(10),
                [$invitee->id],
                1,
            );
            $this->fail('Only requester may open attendance.');
        } catch (VenueBookingAttendanceException $exception) {
            $this->assertSame('ATTENDANCE_FORBIDDEN', $exception->errorCode);
        }

        $booking->applyLifecycleTransition(['status' => VenueBookingStatusEnum::REQUESTED]);
        $this->expectException(VenueBookingAttendanceException::class);
        app(OpenVenueBookingAttendanceRoundHandler::class)->handle(
            $booking->id,
            $requesterActor,
            CarbonImmutable::now()->addMinutes(10),
            [$invitee->id],
            1,
        );
    }

    public function test_repeat_response_updates_one_invitation_and_threshold_is_emitted_once(): void
    {
        Event::fake([
            VenueBookingAttendanceResponded::class,
            VenueBookingAttendanceThresholdReached::class,
        ]);
        [$booking, $requesterActor] = $this->heldBooking();
        [$first] = $this->userAndActor();
        [$second] = $this->userAndActor();
        $round = $this->open($booking, $requesterActor, [$first, $second], 1);
        $handler = app(RespondVenueBookingAttendanceRoundHandler::class);

        $handler->handle($round->id, $first, VenueBookingAttendanceResponseValue::MAYBE);
        $handler->handle($round->id, $first, VenueBookingAttendanceResponseValue::YES);
        $handler->handle($round->id, $first, VenueBookingAttendanceResponseValue::YES);

        $round->refresh();
        $this->assertSame(1, $round->yes_count);
        $this->assertSame(0, $round->maybe_count);
        $this->assertSame(1, $round->pending_count);
        $this->assertDatabaseCount('venue_booking_attendance_responses', 2);
        Event::assertDispatchedTimes(VenueBookingAttendanceResponded::class, 2);
        Event::assertDispatchedTimes(VenueBookingAttendanceThresholdReached::class, 1);
    }

    public function test_uninvited_user_is_forbidden_and_response_privacy_is_enforced(): void
    {
        [$booking, $requesterActor] = $this->heldBooking();
        [$invitee] = $this->userAndActor();
        [$stranger] = $this->userAndActor();
        $round = $this->open($booking, $requesterActor, [$invitee], 1, 'organizer');

        $this->actingAs($stranger)
            ->postJson(route('venue-booking-attendance.respond', $round), ['response' => 'yes'])
            ->assertForbidden()
            ->assertJsonPath('code', 'ATTENDANCE_FORBIDDEN');
        $this->actingAs($invitee)
            ->getJson(route('venue-booking-attendance.show', $round))
            ->assertOk()
            ->assertJsonPath('responses', null)
            ->assertJsonPath('extends_hold', false);
    }

    public function test_close_is_idempotent_and_scheduler_closes_expired_round(): void
    {
        Event::fake([VenueBookingAttendanceRoundClosed::class]);
        [$booking, $requesterActor] = $this->heldBooking();
        [$invitee] = $this->userAndActor();
        $round = $this->open($booking, $requesterActor, [$invitee], 1);
        $handler = app(CloseVenueBookingAttendanceRoundHandler::class);

        $handler->handle($round->id, $requesterActor);
        $handler->handle($round->id, $requesterActor);

        $this->assertSame(VenueBookingAttendanceRoundStatus::CLOSED, $round->refresh()->status);
        Event::assertDispatchedTimes(VenueBookingAttendanceRoundClosed::class, 1);

        $second = $this->open($booking, $requesterActor, [$invitee], 1);
        Carbon::setTestNow($second->deadline_at);
        $this->assertSame(1, app(CloseExpiredVenueBookingAttendanceRoundsHandler::class)->handle());
        $this->assertSame('deadline', $second->refresh()->close_reason);
    }

    public function test_booking_status_change_closes_open_round_and_disabled_flag_is_noop(): void
    {
        [$booking, $requesterActor] = $this->heldBooking();
        [$invitee] = $this->userAndActor();
        $round = $this->open($booking, $requesterActor, [$invitee], 1);

        app(CloseAttendanceRoundAfterBookingEnds::class)->handle(new VenueBookingConfirmed($booking->id));
        $this->assertSame('booking_status_changed', $round->refresh()->close_reason);

        config()->set('features.venue_rental.attendance_v2', false);
        $this->assertSame(0, app(CloseExpiredVenueBookingAttendanceRoundsHandler::class)->handle());
        $this->actingAs($invitee)
            ->getJson(route('venue-booking-attendance.show', $round))
            ->assertNotFound()
            ->assertJsonPath('code', 'feature_disabled');
    }

    /** @return array{VenueBooking, Actor} */
    private function heldBooking(): array
    {
        [$requester, $actor] = $this->userAndActor();
        $venue = Venue::factory()->create();
        $booking = VenueBooking::query()->create([
            'public_id' => (string) Str::uuid(),
            'flow' => 'rental',
            'venue_id' => $venue->id,
            'created_by_actor_id' => $actor->id,
            'requester_user_id' => $requester->id,
            'status' => VenueBookingStatusEnum::HELD,
            'scope' => VenueBookingScopeEnum::WHOLE,
            'payment_state' => VenueBookingPaymentState::NOT_REQUIRED,
            'starts_at' => CarbonImmutable::now()->addDay(),
            'ends_at' => CarbonImmutable::now()->addDay()->addHour(),
            'hold_expires_at' => CarbonImmutable::now()->addMinutes(30),
            'effective_protection_until' => CarbonImmutable::now()->addMinutes(30),
            'held_at' => CarbonImmutable::now(),
        ]);

        return [$booking, $actor];
    }

    /** @param list<User> $users */
    private function open(VenueBooking $booking, Actor $actor, array $users, int $minimum, string $visibility = 'participants')
    {
        return app(OpenVenueBookingAttendanceRoundHandler::class)->handle(
            $booking->id,
            $actor,
            CarbonImmutable::now()->addMinutes(20),
            array_map(fn (User $user): int => $user->id, $users),
            $minimum,
            $visibility,
        );
    }

    /** @return array{User, Actor} */
    private function userAndActor(): array
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        return [$user, app(CurrentActorResolver::class)->resolve($user, null)];
    }
}
