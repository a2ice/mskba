<?php

namespace Tests\Feature\Team;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Team\Domain\Enums\TeamVenueRelationTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamVenueRelation;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Database\Seeders\GameLifecycleDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class TeamCatalogDefaultCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_uses_default_category_and_keeps_filters_badges_and_views(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);

        $this->get(route('teams.index', ['view' => 'list']))
            ->assertOk()
            ->assertSee('data-default-category', false)
            ->assertSee('teams-category-catalog', false)
            ->assertSee('Размер состава')
            ->assertSee('Тип команды')
            ->assertSee('Набор игроков')
            ->assertSee('data-default-category-results="cards"', false)
            ->assertSee('data-default-category-results="list"', false)
            ->assertSee('data-default-category-results="map"', false)
            ->assertSee('aria-controls="teams-sidebar-pre-navigation"', false)
            ->assertSee('id="teams-sidebar-pre-navigation"', false)
            ->assertSee(route('participants.players'), false)
            ->assertSee(route('participants.coaches'), false)
            ->assertSee(route('participants.index'), false)
            ->assertSeeInOrder([
                'aria-controls="teams-sidebar-pre-navigation"',
                'aria-controls="teams-sidebar-navigation"',
                'aria-controls="teams-sidebar-filters"',
            ], false)
            ->assertSee('Все команды')
            ->assertSee('Идёт набор')
            ->assertSee('title="Баскетбол"', false)
            ->assertSee('class="is-sport__short" aria-hidden="true">5x5', false);
    }

    public function test_participants_group_is_collapsed_while_team_group_is_open_by_default(): void
    {
        $response = $this->get(route('teams.index'))
            ->assertOk();

        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/aria-expanded="false"\\s+aria-controls="teams-sidebar-pre-navigation"/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/id="teams-sidebar-pre-navigation"[^>]*data-default-category-sidebar-content[^>]*hidden/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/default-category-sidebar-accordion__item is-open[^>]*>.*?aria-expanded="true"\\s+aria-controls="teams-sidebar-navigation"/s',
            $html,
        );
    }

    public function test_map_uses_only_confirmed_team_venue_relations_and_supports_multiple_points(): void
    {
        config(['integrations.yandex.api_key' => 'test-yandex-key']);
        $this->seed(GameLifecycleDemoSeeder::class);

        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $desiredVenue = $this->venueAt('Только желаемая площадка', 55.7510, 37.6170);
        $confirmedVenueA = $this->venueAt('Подтверждённая площадка А', 55.7520, 37.6180);
        $confirmedVenueB = $this->venueAt('Подтверждённая площадка Б', 55.7530, 37.6190);

        TeamVenueRelation::query()->create([
            'team_id' => $team->id,
            'venue_id' => $desiredVenue->id,
            'relation_type' => TeamVenueRelationTypeEnum::DESIRED,
            'created_by_user_id' => $creator->id,
        ]);
        foreach ([$confirmedVenueA, $confirmedVenueB] as $venue) {
            TeamVenueRelation::query()->create([
                'team_id' => $team->id,
                'venue_id' => $venue->id,
                'relation_type' => TeamVenueRelationTypeEnum::CONFIRMED,
                'created_by_user_id' => $creator->id,
            ]);
        }

        $response = $this->get(route('teams.index', ['view' => 'map']));
        $response
            ->assertOk()
            ->assertSee('data-team-category-map', false)
            ->assertSee('data-yandex-map-api-key="test-yandex-key"', false);

        $points = collect($this->mapPoints($response));
        $venueNames = $points->pluck('venue_name');

        $this->assertTrue($venueNames->contains('Подтверждённая площадка А'));
        $this->assertTrue($venueNames->contains('Подтверждённая площадка Б'));
        $this->assertFalse($venueNames->contains('Только желаемая площадка'));
        $this->assertSame(2, $venueNames->filter(
            fn (string $name): bool => in_array($name, ['Подтверждённая площадка А', 'Подтверждённая площадка Б'], true),
        )->count());
    }

    /** @return array<int, array<string, mixed>> */
    private function mapPoints(TestResponse $response): array
    {
        $matches = [];
        $matched = preg_match(
            '/<script type="application\/json" data-team-category-map-points>(.*?)<\/script>/s',
            $response->getContent(),
            $matches,
        );

        $this->assertSame(1, $matched, 'Team catalog map points JSON was not rendered.');

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function venueAt(string $name, float $latitude, float $longitude): Venue
    {
        $address = Address::factory()->create([
            'city' => 'Москва',
            'street' => 'Тестовая улица',
            'building' => '1',
            'full_address' => "Москва, {$name}",
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
        $location = Location::factory()->create(['address_id' => $address->id]);

        return Venue::factory()->create([
            'name' => $name,
            'status' => VenueStatusEnum::CONFIRMED,
            'location_id' => $location->id,
            'raw_address' => $address->full_address,
        ]);
    }
}
