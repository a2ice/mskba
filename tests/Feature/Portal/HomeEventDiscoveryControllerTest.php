<?php

namespace Tests\Feature\Portal;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Tournament\Domain\Enums\TournamentEnrollmentPolicyEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeEventDiscoveryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_merges_public_events_and_tournaments_and_applies_location_filters(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 12:00:00', 'Europe/Moscow'));

        $moscow = City::factory()->create(['name' => 'Москва', 'alias' => 'moscow']);
        $district = District::factory()->for($moscow)->create([
            'name' => 'Северный административный округ',
            'short_name' => 'САО',
            'alias' => 'sao',
        ]);
        $station = MetroStation::factory()->create(['name' => 'Динамо']);
        $venue = $this->venueIn($moscow, $district, 'Ленинградский проспект', 'Target Hall');
        $venue->location->metroStations()->attach($station->id);

        $event = Event::factory()->create([
            'venue_id' => $venue->id,
            'title' => 'Игра в САО',
            'type' => EventTypeEnum::GAME->value,
            'status' => EventStatusEnum::PUBLISHED->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => CarbonImmutable::parse('2026-09-10 19:00:00', 'Europe/Moscow'),
            'ends_at' => CarbonImmutable::parse('2026-09-10 21:00:00', 'Europe/Moscow'),
        ]);
        $game = Game::query()->create([
            'event_id' => $event->id,
            'created_by_actor_id' => $event->organizer_actor_id,
            'format' => GameFormatEnum::BASKETBALL_5X5->value,
            'recruitment_mode' => GameRecruitmentModeEnum::PREFORMED_TEAMS->value,
        ]);
        $event->update(['primary_game_id' => $game->id]);

        Tournament::factory()->create([
            'default_venue_id' => $venue->id,
            'title' => 'Лига САО',
            'status' => TournamentStatusEnum::CONFIRMED->value,
            'format' => GameFormatEnum::BASKETBALL_5X5->value,
            'enrollment_policy' => TournamentEnrollmentPolicyEnum::CONTINUOUS->value,
            'starts_on' => '2026-09-11',
            'ends_on' => '2026-09-13',
        ]);

        $khimki = City::factory()->create(['name' => 'Химки', 'alias' => 'khimki']);
        $otherVenue = $this->venueIn($khimki, null, 'Юбилейный проспект', 'Other Hall');
        Event::factory()->create([
            'venue_id' => $otherVenue->id,
            'title' => 'Игра в Химках',
            'type' => EventTypeEnum::GAME->value,
            'status' => EventStatusEnum::PUBLISHED->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => CarbonImmutable::parse('2026-09-10 20:00:00', 'Europe/Moscow'),
            'ends_at' => CarbonImmutable::parse('2026-09-10 22:00:00', 'Europe/Moscow'),
        ]);

        $response = $this->getJson(route('home.event-discovery', [
            'type' => 'any',
            'city_id' => $moscow->id,
            'district_id' => $district->id,
            'metro_station_ids' => [$station->id],
            'street' => 'Ленинградский проспект',
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-14',
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('results.0.title', 'Игра в САО')
            ->assertJsonPath('results.0.kind', 'event')
            ->assertJsonPath('results.1.title', 'Лига САО')
            ->assertJsonPath('results.1.kind', 'tournament');
    }

    public function test_game_discovery_applies_format_and_recruitment_mode(): void
    {
        $venue = Venue::factory()->create(['name' => 'Game Hall']);

        $matching = Event::factory()->create([
            'venue_id' => $venue->id,
            'title' => 'Подходящая игра',
            'type' => EventTypeEnum::GAME->value,
            'status' => EventStatusEnum::PUBLISHED->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHours(2),
        ]);
        $matchingGame = Game::query()->create([
            'event_id' => $matching->id,
            'created_by_actor_id' => $matching->organizer_actor_id,
            'format' => GameFormatEnum::STREETBALL_3X3->value,
            'recruitment_mode' => GameRecruitmentModeEnum::INDIVIDUAL_DRAFT->value,
        ]);
        $matching->update(['primary_game_id' => $matchingGame->id]);

        $other = Event::factory()->create([
            'venue_id' => $venue->id,
            'title' => 'Другая игра',
            'type' => EventTypeEnum::GAME->value,
            'status' => EventStatusEnum::PUBLISHED->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
        ]);
        $otherGame = Game::query()->create([
            'event_id' => $other->id,
            'created_by_actor_id' => $other->organizer_actor_id,
            'format' => GameFormatEnum::BASKETBALL_5X5->value,
            'recruitment_mode' => GameRecruitmentModeEnum::PREFORMED_TEAMS->value,
        ]);
        $other->update(['primary_game_id' => $otherGame->id]);

        $response = $this->getJson(route('home.event-discovery', [
            'type' => 'game',
            'format' => GameFormatEnum::STREETBALL_3X3->value,
            'game_mode' => GameRecruitmentModeEnum::INDIVIDUAL_DRAFT->value,
            'date_from' => now()->toDateString(),
            'date_to' => now()->addDays(7)->toDateString(),
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.title', 'Подходящая игра')
            ->assertJsonPath('results.0.format', GameFormatEnum::STREETBALL_3X3->value)
            ->assertJsonPath('results.0.recruitment_mode', GameRecruitmentModeEnum::INDIVIDUAL_DRAFT->value);
    }

    private function venueIn(City $city, ?District $district, string $street, string $name): Venue
    {
        $address = Address::factory()->create([
            'city_id' => $city->id,
            'district_id' => $district?->id,
            'city' => $city->name,
            'street' => $street,
            'building' => '1',
            'full_address' => $city->name.', '.$street.', 1',
        ]);
        $location = Location::factory()->create(['address_id' => $address->id]);

        return Venue::factory()->create([
            'name' => $name,
            'location_id' => $location->id,
            'raw_address' => $address->full_address,
        ])->load('location');
    }
}
