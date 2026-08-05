<?php

namespace Tests\Feature\Database;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Models\Team;
use Database\Seeders\GameLifecycleDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameLifecycleDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_repeatable_browser_testing_scenarios(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $this->seed(GameLifecycleDemoSeeder::class);

        $this->assertSame(13, User::query()->where('username', 'like', 'demo-%')->count());
        $this->assertSame(5, Event::query()->where('alias', 'like', 'demo-%')->count());

        $planned = Event::query()->where('alias', 'demo-game-planned')->firstOrFail();
        $live = Event::query()->where('alias', 'demo-mini-game-live')->firstOrFail();
        $review = Event::query()->where('alias', 'demo-mini-game-review')->firstOrFail();
        $completed = Event::query()->where('alias', 'demo-game-completed')->firstOrFail();

        $this->assertNull($planned->actual_started_at);
        $this->assertNotNull($live->actual_started_at);
        $this->assertNull($live->actual_ended_at);
        $this->assertNotNull($review->actual_ended_at);
        $this->assertSame(GameStatisticsStatusEnum::READY, $review->gameDetail->statistics_status);
        $this->assertSame(EventStatusEnum::COMPLETED, $completed->status);
        $this->assertSame(GameStatisticsStatusEnum::CONFIRMED, $completed->gameDetail->statistics_status);
        $this->assertSame(2, $review->parentEvent->childGames()->count());
        $this->assertCount(12, $review->parentEvent->participants);

        $organizer = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $this->actingAs($organizer)
            ->get(route('events.show', $planned->routeIdentifier()))
            ->assertOk();
        $this->get(route('teams.index'))
            ->assertOk()
            ->assertSee('[DEMO] Красные')
            ->assertSee('[DEMO] Синие')
            ->assertDontSee('team-catalog-card__tags', false)
            ->assertSee('Тренер: Демо Организатор')
            ->assertSee('Капитан: Игрок 1 Красные')
            ->assertSee('ti-user-cog', false)
            ->assertSee('images/team-placeholder.webp');
        $this->get(route('teams.index', ['q' => 'Красные', 'member_count' => 'medium', 'sport_type' => 'streetball']))
            ->assertOk()
            ->assertSee('[DEMO] Красные')
            ->assertDontSee('[DEMO] Синие');
        $redTeam = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $this->get(route('teams.show', $redTeam->routeIdentifier()))
            ->assertOk()
            ->assertSee('Тренерский штаб')
            ->assertSee('Демо Организатор')
            ->assertSee('Составы по дисциплинам')
            ->assertSee('Баскетбол')
            ->assertSee('data-starter-count', false)
            ->assertSee('Стритбол')
            ->assertSee('Запасные')
            ->assertSee('Капитан')
            ->assertSee('data-team-tooltip="Игрок 2 Красные"', false)
            ->assertSee('data-team-tooltip="Назначить капитаном"', false)
            ->assertSee('ti-star', false)
            ->assertDontSee('>Назначить капитаном</button>', false)
            ->assertSee('data-team-roster', false)
            ->assertSee('team-person--coach', false);
        $this->post(route('teams.store'), ['name' => 'Команда без спортивных ролей'])
            ->assertRedirect();
        $this->get(route('teams.index', ['q' => 'Команда без спортивных ролей']))
            ->assertOk()
            ->assertSee('Тренер: —')
            ->assertSee('Капитан: —');
        $this->getJson(route('events.game.lifecycle.show', $planned->routeIdentifier()))
            ->assertOk()
            ->assertJsonPath('can_start', true)
            ->assertJsonPath('can_manage_lineup', true);
        $this->getJson(route('events.game.lifecycle.show', $live->routeIdentifier()))
            ->assertOk()
            ->assertJsonPath('can_end', true)
            ->assertJsonPath('can_enter_statistics', true);
        $this->getJson(route('events.game.lifecycle.show', $review->routeIdentifier()))
            ->assertOk()
            ->assertJsonPath('can_confirm_result', true);
    }
}
