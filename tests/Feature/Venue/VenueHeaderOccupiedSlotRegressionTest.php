<?php

namespace Tests\Feature\Venue;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\VenueBooking as LegacyVenueBooking;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\UseCases\ShowVenueHandler;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class VenueHeaderOccupiedSlotRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-08 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_public_event_link_is_rendered_with_type_and_status_icon_tooltip_without_event_title(): void
    {
        [$venue, $booking, $actorId] = $this->venueAndBooking();
        $event = Event::factory()->create([
            'venue_id' => $venue->id,
            'booking_id' => $booking->id,
            'organizer_actor_id' => $actorId,
            'title' => 'Автогенерируемое название события 123',
            'alias' => 'training-yakhromskaya',
            'type' => EventTypeEnum::TRAINING,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'starts_at' => '2026-09-12 19:00:00',
            'ends_at' => '2026-09-12 20:00:00',
        ]);

        $this->assertNull($booking->event_id);

        $this
            ->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('data-day-date="2026-09-12"', false)
            ->assertSee('is-confirmed', false)
            ->assertSee('Тренировка')
            ->assertSee('aria-label="19:00–20:00 · Тренировка · ✅ Подтверждено"', false)
            ->assertDontSee('Автогенерируемое название события 123')
            ->assertSee('href="'.route('events.show', $event->routeIdentifier()).'"', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('data-venue-occupancy-prev', false)
            ->assertSee('data-venue-occupancy-next', false);
    }

    public function test_private_event_keeps_type_and_status_icon_but_is_not_linked_and_does_not_expose_title(): void
    {
        [$venue, $booking, $actorId] = $this->venueAndBooking();
        $event = Event::factory()->create([
            'venue_id' => $venue->id,
            'booking_id' => $booking->id,
            'organizer_actor_id' => $actorId,
            'title' => 'Закрытая тренировка — служебное название',
            'alias' => 'private-training',
            'type' => EventTypeEnum::TRAINING,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PRIVATE,
            'starts_at' => '2026-09-12 19:00:00',
            'ends_at' => '2026-09-12 20:00:00',
        ]);

        $this
            ->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('aria-label="19:00–20:00 · Тренировка · ✅ Подтверждено"', false)
            ->assertDontSee('href="'.route('events.show', $event->routeIdentifier()).'"', false)
            ->assertDontSee('Закрытая тренировка — служебное название');
    }

    public function test_pending_event_shows_pending_booking_status_only_in_icon_tooltip_without_public_link(): void
    {
        [$venue, $booking, $actorId] = $this->venueAndBooking(VenueBookingStatusEnum::PENDING);
        $event = Event::factory()->create([
            'venue_id' => $venue->id,
            'booking_id' => $booking->id,
            'organizer_actor_id' => $actorId,
            'title' => 'Черновое событие',
            'alias' => 'pending-training',
            'type' => EventTypeEnum::TRAINING,
            'status' => EventStatusEnum::DRAFT,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'starts_at' => '2026-09-12 19:00:00',
            'ends_at' => '2026-09-12 20:00:00',
        ]);

        $this
            ->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('is-tentative', false)
            ->assertSee('aria-label="19:00–20:00 · Тренировка · 🕒 Ожидает подтверждения"', false)
            ->assertDontSee('href="'.route('events.show', $event->routeIdentifier()).'"', false)
            ->assertDontSee('Черновое событие');
    }

    public function test_booking_without_event_still_explains_why_slot_is_occupied_via_type_and_status_tooltip(): void
    {
        [$venue] = $this->venueAndBooking(VenueBookingStatusEnum::PENDING);

        $this
            ->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('aria-label="19:00–20:00 · Бронирование · 🕒 Ожидает подтверждения"', false)
            ->assertSee('Бронирование');
    }

    public function test_occupancy_calendar_contains_exactly_nine_days_from_today(): void
    {
        [$venue] = $this->venueAndBooking();

        $response = $this
            ->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('data-day-date="2026-09-08"', false)
            ->assertSee('data-day-date="2026-09-16"', false)
            ->assertDontSee('data-day-date="2026-09-17"', false);

        $this->assertSame(9, substr_count($response->getContent(), 'data-venue-occupancy-day'));
    }


    public function test_future_public_event_slot_exposes_join_action_for_venue_dialog(): void
    {
        [$venue, $booking, $actorId] = $this->venueAndBooking();
        $event = Event::factory()->create([
            'venue_id' => $venue->id,
            'booking_id' => $booking->id,
            'organizer_actor_id' => $actorId,
            'title' => 'Открытая тренировка',
            'type' => EventTypeEnum::TRAINING,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'starts_at' => '2026-09-12 19:00:00',
            'ends_at' => '2026-09-12 20:00:00',
        ]);

        $days = app(ShowVenueHandler::class)->handle($venue->routeIdentifier(), null)->occupancyDays;
        $slot = collect($days)->flatMap(fn (array $day) => $day['slots'])->first();

        $this->assertNotNull($slot);
        $this->assertSame('Открытая тренировка', $slot['eventTitle']);
        $this->assertTrue($slot['canJoin']);
        $this->assertSame(route('events.show', $event->routeIdentifier()), $slot['eventUrl']);
        $this->assertSame(route('events.participation', $event->routeIdentifier()), $slot['eventJoinUrl']);
    }

    public function test_private_event_slot_never_exposes_join_url_or_event_name(): void
    {
        [$venue, $booking, $actorId] = $this->venueAndBooking();
        Event::factory()->create([
            'venue_id' => $venue->id,
            'booking_id' => $booking->id,
            'organizer_actor_id' => $actorId,
            'title' => 'Скрытая игра',
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PRIVATE,
            'starts_at' => '2026-09-12 19:00:00',
            'ends_at' => '2026-09-12 20:00:00',
        ]);

        $days = app(ShowVenueHandler::class)->handle($venue->routeIdentifier(), null)->occupancyDays;
        $slot = collect($days)->flatMap(fn (array $day) => $day['slots'])->first();

        $this->assertNotNull($slot);
        $this->assertFalse($slot['canJoin']);
        $this->assertNull($slot['eventUrl']);
        $this->assertNull($slot['eventJoinUrl']);
        $this->assertNull($slot['eventTitle']);
    }


    public function test_full_public_event_allows_tentative_response_without_confirming_a_spot(): void
    {
        [$venue, $booking, $actorId] = $this->venueAndBooking();
        $event = Event::factory()->create([
            'venue_id' => $venue->id,
            'booking_id' => $booking->id,
            'organizer_actor_id' => $actorId,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'max_participants' => 1,
            'starts_at' => '2026-09-12 19:00:00',
            'ends_at' => '2026-09-12 20:00:00',
        ]);
        $joined = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $event->participants()->create([
            'user_id' => $joined->id,
            'role' => EventParticipantRoleEnum::PARTICIPANT,
            'participation_roles' => ['player'],
            'status' => EventParticipantStatusEnum::CONFIRMED,
            'confirmation_version' => $event->participation_confirmation_version,
        ]);
        $viewer = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $days = app(ShowVenueHandler::class)->handle($venue->routeIdentifier(), $viewer)->occupancyDays;
        $slot = collect($days)->flatMap(fn (array $day) => $day['slots'])->first();

        $this->assertSame('full', $slot['joinState']);
        $this->assertFalse($slot['canJoin']);
        $this->assertTrue($slot['canRespondTentative']);
        $this->assertSame(route('events.participation', $event->routeIdentifier()), $slot['eventJoinUrl']);
    }

    public function test_already_confirmed_participant_gets_specific_join_state(): void
    {
        [$venue, $booking, $actorId] = $this->venueAndBooking();
        $event = Event::factory()->create([
            'venue_id' => $venue->id,
            'booking_id' => $booking->id,
            'organizer_actor_id' => $actorId,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'starts_at' => '2026-09-12 19:00:00',
            'ends_at' => '2026-09-12 20:00:00',
        ]);
        $viewer = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $event->participants()->create([
            'user_id' => $viewer->id,
            'role' => EventParticipantRoleEnum::PARTICIPANT,
            'participation_roles' => ['player'],
            'status' => EventParticipantStatusEnum::CONFIRMED,
            'confirmation_version' => $event->participation_confirmation_version,
        ]);

        $days = app(ShowVenueHandler::class)->handle($venue->routeIdentifier(), $viewer)->occupancyDays;
        $slot = collect($days)->flatMap(fn (array $day) => $day['slots'])->first();

        $this->assertSame('joined', $slot['joinState']);
        $this->assertFalse($slot['canJoin']);
        $this->assertFalse($slot['canRespondTentative']);
        $this->assertNull($slot['eventJoinUrl']);
    }

    public function test_event_organizer_uses_dedicated_join_action_without_losing_permissions(): void
    {
        [$venue, $booking, $actorId] = $this->venueAndBooking();
        $event = Event::factory()->create([
            'venue_id' => $venue->id,
            'booking_id' => $booking->id,
            'organizer_actor_id' => $actorId,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'max_participants' => 10,
            'starts_at' => '2026-09-12 19:00:00',
            'ends_at' => '2026-09-12 20:00:00',
        ]);
        $organizer = \App\Modules\Identity\Domain\Models\Actor::query()->findOrFail($actorId)->user;
        $event->participants()->create([
            'user_id' => $organizer->id,
            'role' => EventParticipantRoleEnum::ORGANIZER,
            'participation_roles' => ['player'],
            'status' => EventParticipantStatusEnum::LEFT,
            'confirmation_version' => $event->participation_confirmation_version,
        ]);

        $days = app(ShowVenueHandler::class)->handle($venue->routeIdentifier(), $organizer)->occupancyDays;
        $slot = collect($days)->flatMap(fn (array $day) => $day['slots'])->first();

        $this->assertSame('open', $slot['joinState']);
        $this->assertTrue($slot['canJoin']);
        $this->assertSame('POST', $slot['eventJoinMethod']);
        $this->assertSame(route('events.join', $event->routeIdentifier()), $slot['eventJoinUrl']);
    }

    /** @return array{0: Venue, 1: LegacyVenueBooking, 2: int} */
    private function venueAndBooking(
        VenueBookingStatusEnum $status = VenueBookingStatusEnum::CONFIRMED,
    ): array {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $actor = app(CurrentActorResolver::class)->resolve($user, null);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'name' => 'Кампус тест',
            'alias' => 'campus-test',
        ]);
        $booking = LegacyVenueBooking::query()->create([
            'venue_id' => $venue->id,
            'created_by_actor_id' => $actor->id,
            'status' => $status,
            'scope' => VenueBookingScopeEnum::WHOLE,
            'starts_at' => '2026-09-12 19:00:00',
            'ends_at' => '2026-09-12 20:00:00',
        ]);

        return [$venue, $booking, $actor->id];
    }
}
