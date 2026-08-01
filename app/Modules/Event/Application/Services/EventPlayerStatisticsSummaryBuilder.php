<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\GamePlayerStatistic;
use Illuminate\Support\Collection;

final class EventPlayerStatisticsSummaryBuilder
{
    /**
     * @return Collection<int, array{
     *     shots_made: int,
     *     shots_attempted: int,
     *     rebounds: int,
     *     assists: int,
     *     turnovers: int,
     *     fouls: int,
     *     last_game_identifier: string
     * }>
     */
    public function build(Event $event): Collection
    {
        $event->loadMissing(['childGames.gameDetail', 'childGames.gamePlayerStatistics']);

        $confirmedGames = $event->childGames
            ->filter(fn (Event $game): bool => $game->gameDetail?->statistics_status === GameStatisticsStatusEnum::CONFIRMED)
            ->sortBy(fn (Event $game): string => sprintf(
                '%s-%020d',
                $game->completed_at?->format('Y-m-d H:i:s.u')
                    ?? $game->ends_at?->format('Y-m-d H:i:s.u')
                    ?? $game->starts_at?->format('Y-m-d H:i:s.u')
                    ?? '0000-00-00 00:00:00.000000',
                $game->id,
            ));

        /** @var array<int, array<string, int|string>> $summaries */
        $summaries = [];

        foreach ($confirmedGames as $game) {
            foreach ($game->gamePlayerStatistics as $statistic) {
                $summary = $summaries[$statistic->user_id] ?? $this->emptySummary();
                $summary['shots_made'] += $this->shotsMade($statistic);
                $summary['shots_attempted'] += $this->shotsAttempted($statistic);
                $summary['rebounds'] += $statistic->offensive_rebounds + $statistic->defensive_rebounds;
                $summary['assists'] += $statistic->assists;
                $summary['turnovers'] += $statistic->turnovers;
                $summary['fouls'] += $statistic->fouls;
                $summary['last_game_identifier'] = $game->routeIdentifier();
                $summaries[$statistic->user_id] = $summary;
            }
        }

        /** @var Collection<int, array{shots_made: int, shots_attempted: int, rebounds: int, assists: int, turnovers: int, fouls: int, last_game_identifier: string}> $result */
        $result = collect($summaries);

        return $result;
    }

    /** @return array{shots_made: int, shots_attempted: int, rebounds: int, assists: int, turnovers: int, fouls: int, last_game_identifier: string} */
    private function emptySummary(): array
    {
        return [
            'shots_made' => 0,
            'shots_attempted' => 0,
            'rebounds' => 0,
            'assists' => 0,
            'turnovers' => 0,
            'fouls' => 0,
            'last_game_identifier' => '',
        ];
    }

    private function shotsMade(GamePlayerStatistic $statistic): int
    {
        return $statistic->close_made
            + $statistic->mid_made
            + $statistic->three_made
            + $statistic->free_throw_made;
    }

    private function shotsAttempted(GamePlayerStatistic $statistic): int
    {
        return $statistic->close_attempted
            + $statistic->mid_attempted
            + $statistic->three_attempted
            + $statistic->free_throw_attempted;
    }
}
