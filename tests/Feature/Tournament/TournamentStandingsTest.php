<?php

namespace Tests\Feature\Tournament;

use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tournament\Application\Services\TournamentStandingsService;
use App\Modules\Tournament\Domain\Enums\TournamentEntrySourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TournamentStandingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_standings_count_only_confirmed_results_once_and_use_documented_tie_breaks(): void
    {
        $tournament = Tournament::factory()->create();
        $entries = collect(['Альфа', 'Бета', 'Гамма'])->map(fn (string $name, int $position) => $tournament->entries()->create([
            'source' => TournamentEntrySourceEnum::ASSEMBLED,
            'name' => $name,
            'status' => TournamentEntryStatusEnum::ACTIVE,
            'position' => $position + 1,
        ]));
        $confirmed = $this->game(10, 7, GameStatisticsStatusEnum::CONFIRMED);
        $draft = $this->game(30, 0, GameStatisticsStatusEnum::READY);
        $tournament->matches()->create(['entry_a_id' => $entries[0]->id, 'entry_b_id' => $entries[1]->id, 'game_id' => $confirmed->id, 'sequence' => 1]);
        $tournament->matches()->create(['entry_a_id' => $entries[1]->id, 'entry_b_id' => $entries[2]->id, 'game_id' => $draft->id, 'sequence' => 2]);

        $rows = app(TournamentStandingsService::class)->build($tournament->load(['entries', 'matches.game.sides']));
        $this->assertSame(['Альфа', 'Гамма', 'Бета'], collect($rows)->pluck('name')->all());
        $this->assertSame([2, 0, 0], collect($rows)->pluck('points')->all());
        $this->assertSame([1, 0, 1], collect($rows)->pluck('played')->all());

        $draft->forceFill(['statistics_status' => GameStatisticsStatusEnum::CONFIRMED, 'status' => GameStatusEnum::COMPLETED])->save();
        $rows = app(TournamentStandingsService::class)->build($tournament->fresh()->load(['entries', 'matches.game.sides']));
        $this->assertSame(2, collect($rows)->firstWhere('name', 'Бета')['played']);
        $this->assertSame(2, collect($rows)->firstWhere('name', 'Бета')['points']);
    }

    public function test_public_tournament_page_shows_sections_and_no_mutation_controls(): void
    {
        $organizer = User::factory()->create(['username' => 'public-organizer']);
        $actor = Actor::factory()->create(['user_id' => $organizer->id, 'type' => 'user']);
        $tournament = Tournament::factory()->create(['created_by_actor_id' => $actor->id]);

        $this->get(route('tournaments.show', $tournament->routeIdentifier()))
            ->assertOk()
            ->assertSee('Организатор:')
            ->assertSee($organizer->username)
            ->assertSee('data-tournament-organizer-trigger', false)
            ->assertSee('Турнирная таблица')
            ->assertSee('Учитываются только подтверждённые результаты')
            ->assertDontSee('Управление турниром')
            ->assertDontSee('Создать игру и бронь');
    }

    private function game(int $scoreA, int $scoreB, GameStatisticsStatusEnum $statisticsStatus): Game
    {
        $event = Event::factory()->create();
        $game = $event->games()->create([
            'created_by_actor_id' => $event->organizer_actor_id,
            'status' => $statisticsStatus === GameStatisticsStatusEnum::CONFIRMED ? GameStatusEnum::COMPLETED : GameStatusEnum::AWAITING_RESULT,
            'statistics_status' => $statisticsStatus,
        ]);
        $game->sides()->create(['slot' => 'A', 'display_name' => 'A', 'score' => $scoreA]);
        $game->sides()->create(['slot' => 'B', 'display_name' => 'B', 'score' => $scoreB]);
        $event->forceFill(['primary_game_id' => $game->id])->save();

        return $game;
    }
}
