<?php

namespace Tests\Feature\Tournament;

use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TournamentIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_tournament_catalog_uses_default_category_and_preserves_period_and_filters(): void
    {
        $startsOn = now()->addDays(10)->toDateString();
        $endsOn = now()->addDays(15)->toDateString();

        Tournament::factory()->create([
            'title' => 'Летний кубок',
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);

        $this->get(route('tournaments.index', [
            'period' => 'upcoming',
            'query' => 'Летний кубок',
            'date_from' => now()->addDay()->toDateString(),
            'date_to' => now()->addMonth()->toDateString(),
        ]))
            ->assertOk()
            ->assertSee('data-default-category', false)
            ->assertSee('tournaments-category-catalog', false)
            ->assertSee('Летний кубок')
            ->assertSee('Дата с')
            ->assertSee('Дата по')
            ->assertSeeInOrder(['Все турниры', 'Текущие', 'Предстоящие', 'Прошедшие'])
            ->assertSee('data-default-category-results="cards"', false)
            ->assertSee('data-default-category-results="list"', false)
            ->assertSee('data-default-category-results="map"', false)
            ->assertSee('Подробнее')
            ->assertDontSee('Раздел турниров находится в разработке.');
    }

    public function test_tournament_map_uses_default_venue_coordinates_and_keeps_unmapped_records_in_catalog(): void
    {
        config(['integrations.yandex.api_key' => 'test-yandex-key']);
        $venue = Venue::factory()->create(['name' => 'Арена турнира']);

        Tournament::factory()->create([
            'title' => 'Турнир на карте',
            'default_venue_id' => $venue->id,
        ]);
        Tournament::factory()->create([
            'title' => 'Турнир без площадки',
            'default_venue_id' => null,
        ]);

        $this->get(route('tournaments.index', ['view' => 'map']))
            ->assertOk()
            ->assertSee('data-tournament-category-map', false)
            ->assertSee('data-yandex-map-api-key="test-yandex-key"', false)
            ->assertSee('Турнир на карте')
            ->assertSee('без координат: 1');

        $this->get(route('tournaments.index', ['view' => 'cards']))
            ->assertOk()
            ->assertSee('Турнир без площадки');
    }

    public function test_tournament_filters_are_validated(): void
    {
        $this->from(route('tournaments.index'))
            ->get(route('tournaments.index', [
                'period' => 'unknown',
                'date_from' => '2026-08-10',
                'date_to' => '2026-08-01',
            ]))
            ->assertRedirect(route('tournaments.index'))
            ->assertSessionHasErrors(['period', 'date_to']);
    }

    public function test_tournaments_are_sorted_by_latest_start_date_first(): void
    {
        Tournament::factory()->create(['title' => 'Более ранний турнир', 'starts_on' => '2026-08-10']);
        Tournament::factory()->create(['title' => 'Более свежий турнир', 'starts_on' => '2026-08-11']);

        $this->get(route('tournaments.index'))
            ->assertOk()
            ->assertSeeInOrder(['Более свежий турнир', 'Более ранний турнир']);
    }
}
