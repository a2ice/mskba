<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Application\Services\EventPlayerStatisticsSummaryBuilder;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\GameDetail;
use App\Modules\Event\Domain\Models\GamePlayerStatistic;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EventPlayerStatisticsSummaryBuilderTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertSame($latestGame->routeIdentifier(), $summary['last_game_identifier']);
    }

    private function createMiniGame(
        Event $parent,
        mixed $completedAt,
        GameStatisticsStatusEnum $statisticsStatus,
    ): Event {
        $game = Event::factory()->create([
            'parent_event_id' => $parent->id,
            'type' => EventTypeEnum::GAME,
            'status' => EventStatusEnum::COMPLETED,
            'starts_at' => $completedAt->copy()->subHour(),
            'ends_at' => $completedAt,
            'completed_at' => $completedAt,
        ]);
        GameDetail::query()->create([
            'event_id' => $game->id,
            'side_a_size' => 1,
            'side_b_size' => 1,
            'statistics_status' => $statisticsStatus,
        ]);

        return $game;
    }

    /** @param array<string, int> $values */
    private function createStatistics(Event $game, User $player, array $values): void
    {
        $side = $game->gameSides()->create([
            'slot' => 'A',
            'display_name' => 'Команда A',
        ]);

        GamePlayerStatistic::query()->create([
            'event_id' => $game->id,
            'game_side_id' => $side->id,
            'user_id' => $player->id,
            ...$values,
        ]);
    }
}
