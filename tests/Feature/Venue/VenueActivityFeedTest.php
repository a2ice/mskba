<?php

namespace Tests\Feature\Venue;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_contains_current_public_event_and_upcoming_event_and_tournament(): void
    {
        $venue = Venue::factory()->create();

        Event::factory()->create([
            'venue_id' => $venue->id,
            'title' => 'Игра прямо сейчас',
            'type' => EventTypeEnum::GAME,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'starts_at' => now()->subMinutes(20),
            'ends_at' => now()->addHour(),
        ]);
        Event::factory()->create([
            'venue_id' => $venue->id,
            'title' => 'Завтрашняя тренировка',
            'type' => EventTypeEnum::TRAINING,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        Event::factory()->create([
            'venue_id' => $venue->id,
            'title' => 'Приватная тренировка',
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PRIVATE,
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(3),
        ]);
        Tournament::factory()->create([
            'default_venue_id' => $venue->id,
            'title' => 'Турнир выходного дня',
            'status' => TournamentStatusEnum::CONFIRMED,
            'starts_on' => today()->addDays(2),
            'ends_on' => today()->addDays(2),
        ]);

        $response = $this->getJson(route('venues.activities', $venue->routeIdentifier()));

        $response->assertOk()
            ->assertJsonPath('current.0.title', 'Игра прямо сейчас')
            ->assertJsonPath('current.0.is_current', true)
            ->assertJsonFragment(['title' => 'Завтрашняя тренировка'])
            ->assertJsonFragment(['title' => 'Турнир выходного дня'])
            ->assertJsonMissing(['title' => 'Приватная тренировка']);
    }
}
