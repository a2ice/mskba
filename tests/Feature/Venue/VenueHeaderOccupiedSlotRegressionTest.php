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

    public function test_public_event_link_is_rendered_with_type_and_booking_status_without_event_title(): void
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
            ->assertSee('12.09.2026 19:00–20:00')
            ->assertSee('Тренировка · ✅ Подтверждено')
            ->assertDontSee('Автогенерируемое название события 123')
            ->assertSee('href="'.route('events.show', $event->routeIdentifier()).'"', false)
            ->assertSee('target="_blank"', false);
    }

    public function test_private_event_keeps_type_and_status_but_is_not_linked_and_does_not_expose_title(): void
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
            ->assertSee('12.09.2026 19:00–20:00 · Тренировка · ✅ Подтверждено')
            ->assertDontSee('href="'.route('events.show', $event->routeIdentifier()).'"', false)
            ->assertDontSee('Закрытая тренировка — служебное название');
    }

    public function test_pending_event_shows_pending_booking_status_without_public_link(): void
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
            ->assertSee('Тренировка · 🕒 Ожидает подтверждения')
            ->assertDontSee('href="'.route('events.show', $event->routeIdentifier()).'"', false)
            ->assertDontSee('Черновое событие');
    }

    public function test_booking_without_event_still_explains_why_slot_is_occupied(): void
    {
        [$venue] = $this->venueAndBooking(VenueBookingStatusEnum::PENDING);

        $this
            ->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('Бронирование · 🕒 Ожидает подтверждения');
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
