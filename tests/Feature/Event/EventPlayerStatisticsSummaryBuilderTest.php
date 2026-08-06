<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Application\Services\EventPlayerStatisticsSummaryBuilder;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\GamePlayerStatistic;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EventPlayerStatisticsSummaryBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_points_follow_selected_scoring_rules(): void
    {
        $statistic = new GamePlayerStatistic([
            'close_made' => 1,
            'mid_made' => 1,
            'three_made' => 1,
            'free_throw_made' => 1,
        ]);

        $this->assertSame(5, $statistic->points(GameScoringTypeEnum::STREETBALL));
        $this->assertSame(8, $statistic->points(GameScoringTypeEnum::BASKETBALL));
    }

    public function test_it_sums_only_confirmed_mini_games_and_links_the_latest_played_game(): void
    {
        $event = Event::factory()->create(['type' => EventTypeEnum::TRAINING]);
        $player = User::factory()->create();
        $olderGame = $this->createMiniGame($event, now()->subHours(3), GameStatisticsStatusEnum::CONFIRMED);
        $latestGame = $this->createMiniGame($event, now()->subHours(2), GameStatisticsStatusEnum::CONFIRMED);
        $draftGame = $this->createMiniGame($event, now()->subHour(), GameStatisticsStatusEnum::READY);

        $this->createStatistics($olderGame, $player, [
            'close_made' => 2,
            'close_attempted' => 3,
            'offensive_rebounds' => 1,
            'assists' => 2,
            'turnovers' => 1,
        ]);
        $this->createStatistics($latestGame, $player, [
            'three_made' => 1,
            'three_attempted' => 4,
            'defensive_rebounds' => 3,
            'assists' => 1,
            'fouls' => 2,
        ]);
        $this->createStatistics($draftGame, $player, [
            'close_made' => 20,
            'close_attempted' => 20,
        ]);

        $summary = app(EventPlayerStatisticsSummaryBuilder::class)
            ->build($event)
            ->get($player->id);

        $this->assertSame(3, $summary['shots_made']);
        $this->assertSame(7, $summary['shots_attempted']);
        $this->assertSame(4, $summary['rebounds']);
        $this->assertSame(3, $summary['assists']);
        $this->assertSame(1, $summary['turnovers']);
        $this->assertSame(2, $summary['fouls']);
        $this->assertSame($latestGame->id, $summary['last_game_id']);
    }

    private function createMiniGame(
        Event $parent,
        mixed $completedAt,
        GameStatisticsStatusEnum $statisticsStatus,
    ): Game {
        return Game::query()->create([
            'event_id' => $parent->id,
            'created_by_actor_id' => $parent->organizer_actor_id,
            'status' => $statisticsStatus === GameStatisticsStatusEnum::CONFIRMED
                ? GameStatusEnum::COMPLETED
                : GameStatusEnum::AWAITING_RESULT,
            'side_a_size' => 1,
            'side_b_size' => 1,
            'statistics_status' => $statisticsStatus,
            'completed_at' => $completedAt,
        ]);
    }

    /** @param array<string, int> $values */
    private function createStatistics(Game $game, User $player, array $values): void
    {
        $side = $game->sides()->create([
            'slot' => 'A',
            'display_name' => 'Команда A',
        ]);

        GamePlayerStatistic::query()->create([
            'game_id' => $game->id,
            'game_side_id' => $side->id,
            'user_id' => $player->id,
            ...$values,
        ]);
    }
}
