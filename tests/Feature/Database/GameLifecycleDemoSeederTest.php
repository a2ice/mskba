<?php

namespace Tests\Feature\Database;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
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
        $this->assertSame(3, Event::query()->where('alias', 'like', 'demo-%')->count());
        $this->assertSame(4, Game::query()->count());

        $plannedEvent = Event::query()->where('alias', 'demo-game-planned')->firstOrFail();
        $planned = $plannedEvent->games()->firstOrFail();
        $this->assertTrue($plannedEvent->primaryGame->is($planned));
        $training = Event::query()->where('alias', 'demo-game-training')->firstOrFail();
        $live = $training->games()->where('title', '[DEMO] Мини-игра — идёт')->firstOrFail();
        $review = $training->games()->where('title', '[DEMO] Мини-игра — проверить результат')->firstOrFail();
        $completedEvent = Event::query()->where('alias', 'demo-game-completed')->firstOrFail();
        $completed = $completedEvent->games()->firstOrFail();
        $this->assertTrue($completedEvent->primaryGame->is($completed));

        $this->assertNull($planned->actual_started_at);
        $this->assertNotNull($live->actual_started_at);
        $this->assertNull($live->actual_ended_at);
        $this->assertNotNull($review->actual_ended_at);
        $this->assertSame(GameStatisticsStatusEnum::READY, $review->statistics_status);
        $this->assertSame(EventStatusEnum::COMPLETED, $completedEvent->status);
        $this->assertSame(GameStatisticsStatusEnum::CONFIRMED, $completed->statistics_status);
        $this->assertSame(2, $training->games()->count());
        $this->assertCount(12, $training->participants);
        $this->assertSame(
            ['demo-red', 'demo-blue'],
            $live->sides()->with('team')->orderBy('slot')->get()->pluck('team.alias')->all(),
        );
        $this->assertNotNull($live->event->venue->location?->address?->latitude);
        $this->assertNotNull($live->event->venue->location?->address?->longitude);

        $organizer = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $this->actingAs($organizer)
            ->get(route('events.show', $plannedEvent->routeIdentifier()))
            ->assertOk();
        $this->get(route('teams.index'))->assertOk();
        $redTeam = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $this->get(route('teams.show', $redTeam->routeIdentifier()))->assertOk();
        $this->getJson(route('events.games.lifecycle.show', [$plannedEvent->routeIdentifier(), $planned->id]))
            ->assertOk()
            ->assertJsonPath('can_start', true)
            ->assertJsonPath('can_manage_lineup', true);
        $this->getJson(route('events.games.lifecycle.show', [$training->routeIdentifier(), $live->id]))
            ->assertOk()
            ->assertJsonPath('can_end', false)
            ->assertJsonPath('can_end_period', true)
            ->assertJsonPath('active_period', 1)
            ->assertJsonPath('can_enter_statistics', true);
        $this->getJson(route('events.games.lifecycle.show', [$training->routeIdentifier(), $review->id]))
            ->assertOk()
            ->assertJsonPath('can_confirm_result', true);
    }
}
