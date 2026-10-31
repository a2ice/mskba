<?php

namespace Tests\Feature\Leagues;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Models\League;
use App\Modules\League\Domain\Models\LeagueSeason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicLeaguePagesTest extends TestCase
{
    use RefreshDatabase;

    private function league(string $name, string $status = 'active'): League
    {
        static $sequence = 0;
        $sequence++;

        return League::query()->create([
            'name' => $name,
            'alias' => 'league-'.$sequence,
            'status' => $status,
            'owner_user_id' => User::factory()->create()->id,
        ]);
    }

    private function season(League $league, string $name, string $status = 'active'): LeagueSeason
    {
        return LeagueSeason::query()->create([
            'league_id' => $league->id,
            'name' => $name,
            'status' => $status,
        ]);
    }

    public function test_guest_catalog_and_detail_hide_drafts_but_allow_archives(): void
    {
        $visible = $this->league('Видимая лига');
        $draft = $this->league('Скрытая лига', 'draft');
        $archived = $this->league('Историческая лига', 'archived');

        $this->get(route('leagues.index'))->assertOk()
            ->assertSee('Видимая лига')
            ->assertSee('Историческая лига')
            ->assertDontSee('Скрытая лига');
        $this->get(route('leagues.show', $visible->alias))->assertOk()->assertSee($visible->name);
        $this->get(route('leagues.show', $archived->alias))->assertOk();
        $this->get(route('leagues.show', $draft->alias))->assertNotFound();
    }

    public function test_catalog_search_status_and_season_filters(): void
    {
        $one = $this->league('Первая лига');
        $two = $this->league('Вторая лига', 'archived');
        $season = $this->season($one, 'Сезон один');

        $this->get(route('leagues.index', ['q' => 'Первая']))->assertOk()
            ->assertSee($one->name)->assertDontSee($two->name);
        $this->get(route('leagues.index', ['status' => 'archived']))->assertOk()
            ->assertSee($two->name)->assertDontSee('href="'.route('leagues.show', $one->alias).'"', false);
        $this->get(route('leagues.index', ['season' => $season->id]))->assertOk()
            ->assertSee($one->name)->assertDontSee($two->name);
    }

    public function test_nested_season_cannot_be_viewed_for_another_league_or_as_draft(): void
    {
        $league = $this->league('Первый клуб');
        $other = $this->league('Другой клуб');
        $season = $this->season($league, 'Сезон 2026');
        $draft = $this->season($league, 'Неопубликованный сезон', 'draft');

        $this->get(route('leagues.seasons.show', [$league->alias, $season->id]))
            ->assertOk()->assertSee('Сезон 2026')->assertDontSee('Неопубликованный сезон');
        $this->get(route('leagues.seasons.show', [$other->alias, $season->id]))->assertNotFound();
        $this->get(route('leagues.seasons.show', [$league->alias, $draft->id]))->assertNotFound();
        $this->get(route('leagues.show', $league->alias))
            ->assertOk()->assertSee('Сезон 2026')->assertDontSee('Неопубликованный сезон');
    }

    public function test_draft_league_does_not_expose_active_season(): void
    {
        $league = $this->league('Черновик лиги', 'draft');
        $season = $this->season($league, 'Открытый сезон');
        $this->get(route('leagues.seasons.show', [$league->alias, $season->id]))->assertNotFound();
        $this->get(route('leagues.index', ['season' => $season->id]))->assertOk()->assertDontSee($league->name);
    }
}
