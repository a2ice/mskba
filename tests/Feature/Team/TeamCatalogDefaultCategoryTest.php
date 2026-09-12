<?php

namespace Tests\Feature\Team;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamVenueRelationTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamVenueRelation;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Database\Seeders\GameLifecycleDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('Все команды')
            ->assertSee('Идёт набор')
            ->assertSee('title="Баскетбол"', false)
            ->assertSee('class="is-sport__short" aria-hidden="true">5x5', false);
    }

    public function test_map_uses_only_confirmed_team_venue_relations_and_supports_multiple_points(): void
    {
        config(['integrations.yandex.api_key' => 'test-yandex-key']);
        $this->seed(GameLifecycleDemoSeeder::class);

        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $desiredVenue = Venue::factory()->create([
            'name' => 'Только желаемая площадка',
            'status' => VenueStatusEnum::CONFIRMED,
        ]);
        $confirmedVenueA = Venue::factory()->create([
            'name' => 'Подтверждённая площадка А',
            'status' => VenueStatusEnum::CONFIRMED,
        ]);
        $confirmedVenueB = Venue::factory()->create([
            'name' => 'Подтверждённая площадка Б',
            'status' => VenueStatusEnum::CONFIRMED,
        ]);

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

        $this->get(route('teams.index', ['view' => 'map']))
            ->assertOk()
            ->assertSee('data-team-category-map', false)
            ->assertSee('data-yandex-map-api-key="test-yandex-key"', false)
            ->assertSee('Подтверждённая площадка А')
            ->assertSee('Подтверждённая площадка Б')
            ->assertDontSee('Только желаемая площадка')
            ->assertSee('точек: 2');
    }
}
