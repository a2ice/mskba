<?php

namespace Tests\Feature\Venue;

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

    public function test_public_event_link_is_rendered_when_booking_points_to_event_only_through_events_booking_id(): void
    {
        [$venue, $booking, $actorId] = $this->venueAndBooking();
        $event = Event::factory()->create([
            'venue_id' => $venue->id,
            'booking_id' => $booking->id,
            'organizer_actor_id' => $actorId,
            'title' => 'Тренировка на Яхромской',
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
            ->assertSee('12.09.2026 19:00–20:00')
            ->assertSee('Тренировка')
            ->assertSee('href="'.route('events.show', $event->routeIdentifier()).'"', false)
            ->assertSee('target="_blank"', false);
    }

    public function test_private_event_is_not_exposed_from_occupied_slot(): void
    {
        [$venue, $booking, $actorId] = $this->venueAndBooking();
        $event = Event::factory()->create([
            'venue_id' => $venue->id,
            'booking_id' => $booking->id,
            'organizer_actor_id' => $actorId,
            'title' => 'Закрытая тренировка',
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
            ->assertSee('12.09.2026 19:00–20:00')
            ->assertDontSee('href="'.route('events.show', $event->routeIdentifier()).'"', false)
            ->assertDontSee('Закрытая тренировка');
    }

    /** @return array{0: Venue, 1: LegacyVenueBooking, 2: int} */
    private function venueAndBooking(): array
    {
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
            'status' => VenueBookingStatusEnum::CONFIRMED,
            'scope' => VenueBookingScopeEnum::WHOLE,
            'starts_at' => '2026-09-12 19:00:00',
            'ends_at' => '2026-09-12 20:00:00',
        ]);

        return [$venue, $booking, $actor->id];
    }
}
