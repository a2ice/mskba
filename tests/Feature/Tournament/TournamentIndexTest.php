<?php

namespace Tests\Feature\Tournament;

use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TournamentIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_tournament_catalog_exposes_periods_filters_and_real_records(): void
    {
        Tournament::factory()->create([
            'title' => 'Летний кубок',
            'starts_on' => '2026-08-15',
            'ends_on' => '2026-08-20',
        ]);

        $this->get(route('tournaments.index', [
            'period' => 'upcoming',
            'query' => 'Летний кубок',
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31',
        ]))
            ->assertOk()
            ->assertSee('Предстоящие турниры')
            ->assertSee('Летний кубок')
            ->assertSee('Поиск по названию')
            ->assertSeeInOrder(['Все', 'Текущие', 'Предстоящие', 'Прошедшие'])
            ->assertSee('Подробнее')
            ->assertDontSee('Раздел турниров находится в разработке.');
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
}
