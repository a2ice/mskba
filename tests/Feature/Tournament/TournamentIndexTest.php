<?php

namespace Tests\Feature\Tournament;

use Tests\TestCase;

final class TournamentIndexTest extends TestCase
{
    public function test_tournament_placeholder_exposes_periods_and_filters(): void
    {
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
            ->assertSee('Раздел турниров находится в разработке.');
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
