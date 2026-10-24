<?php

namespace Tests\Feature\Portal;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HomeTodayEventMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_public_events_with_open_participation_and_geocoded_venues_appear(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', 'Europe/Moscow'));
        try {
            $address = Address::factory()->create(['latitude' => 55.82, 'longitude' => 37.45]);
            $venue = Venue::factory()->create([
                'status' => VenueStatusEnum::CONFIRMED,
                'location_id' => Location::factory()->create(['address_id' => $address->id])->id,
            ]);
            $visible = Event::factory()->create([
                'venue_id' => $venue->id,
                'title' => 'Игра в Тушино',
                'type' => EventTypeEnum::TRAINING,
                'status' => EventStatusEnum::PUBLISHED,
                'visibility' => EventVisibilityEnum::PUBLIC,
                'starts_at' => '2026-10-23 18:00:00',
                'ends_at' => '2026-10-23 20:00:00',
            ]);
            foreach ([
                ['status' => EventStatusEnum::CANCELLED],
                ['visibility' => EventVisibilityEnum::PRIVATE],
                ['starts_at' => '2026-10-22 18:00:00', 'ends_at' => '2026-10-22 20:00:00'],
                ['starts_at' => '2026-10-24 18:00:00', 'ends_at' => '2026-10-24 20:00:00'],
                ['starts_at' => '2026-10-23 08:00:00', 'ends_at' => '2026-10-23 10:00:00'],
            ] as $override) {
                Event::factory()->create(array_replace([
                    'venue_id' => $venue->id,
                    'type' => EventTypeEnum::TRAINING,
                    'status' => EventStatusEnum::PUBLISHED,
                    'visibility' => EventVisibilityEnum::PUBLIC,
                    'starts_at' => '2026-10-23 18:00:00',
                    'ends_at' => '2026-10-23 20:00:00',
                ], $override));
            }

            $this->getJson(route('home.today-event-map'))
                ->assertOk()->assertJsonPath('count', 1)
                ->assertJsonPath('events.0.id', $visible->id)
                ->assertJsonPath('events.0.latitude', 55.82)
                ->assertJsonPath('events.0.starts_at', '18:00')
                ->assertJsonPath('events.0.type_label', 'Тренировка');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_non_player_staff_roles_do_not_consume_remaining_player_places(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00', 'Europe/Moscow'));
        try {
            $address = Address::factory()->create(['latitude' => 55.82, 'longitude' => 37.45]);
            $venue = Venue::factory()->create([
                'status' => VenueStatusEnum::CONFIRMED,
                'location_id' => Location::factory()->create(['address_id' => $address->id])->id,
            ]);
            $event = Event::factory()->create([
                'venue_id' => $venue->id,
                'type' => EventTypeEnum::TRAINING,
                'status' => EventStatusEnum::PUBLISHED,
                'visibility' => EventVisibilityEnum::PUBLIC,
                'starts_at' => '2026-10-24 18:00:00',
                'ends_at' => '2026-10-24 20:00:00',
                'max_participants' => 1,
            ])->refresh();
            $staff = User::factory()->create();
            $event->participants()->create([
                'user_id' => $staff->id,
                'role' => 'participant',
                'status' => 'confirmed',
                'participation_roles' => ['coach', 'statistician'],
                'confirmation_version' => $event->participation_confirmation_version,
            ]);

            $this->getJson(route('home.today-event-map'))
                ->assertOk()->assertJsonPath('count', 1)
                ->assertJsonPath('events.0.spots_left', 1);

            $event->participants()->create([
                'user_id' => User::factory()->create()->id,
                'role' => 'participant',
                'status' => 'confirmed',
                'participation_roles' => ['player', 'coach'],
                'confirmation_version' => $event->participation_confirmation_version,
            ]);

            $this->getJson(route('home.today-event-map'))
                ->assertOk()->assertJsonPath('count', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_events_without_venue_coordinates_are_not_shown(): void
    {
        $address = Address::factory()->create(['latitude' => null, 'longitude' => null]);
        $location = Location::factory()->create(['address_id' => $address->id]);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'location_id' => $location->id,
        ]);
        Event::factory()->create([
            'venue_id' => $venue->id,
            'starts_at' => now()->addMinutes(10),
            'ends_at' => now()->addMinutes(90),
        ]);
        $this->getJson(route('home.today-event-map'))->assertOk()->assertJsonPath('count', 0);
    }
}
