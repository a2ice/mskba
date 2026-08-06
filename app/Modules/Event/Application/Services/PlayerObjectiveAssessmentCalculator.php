<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Models\GamePlayerStatistic;
use App\Modules\Identity\Domain\Models\Participation\PlayerObjectiveAssessment;
use Illuminate\Support\Facades\Cache;

final class PlayerObjectiveAssessmentCalculator
{
    private const FORMULA_VERSION = 1;

    public function recalculateForGame(int $gameId): void
    {
        GamePlayerStatistic::query()
            ->where('game_id', $gameId)
            ->distinct()
            ->pluck('user_id')
            ->each(fn (int $userId) => Cache::lock("player-objective-assessment:{$userId}", 30)
                ->block(5, fn () => $this->recalculateForUser($userId)));
    }

    private function recalculateForUser(int $userId): void
    {
        $statistics = GamePlayerStatistic::query()
            ->where('user_id', $userId)
            ->whereHas('game', fn ($query) => $query
                ->where('statistics_status', GameStatisticsStatusEnum::CONFIRMED->value))
            ->get();

        if ($statistics->isEmpty()) {
            return;
        }

        $gamesCount = $statistics->pluck('game_id')->unique()->count();
        $minutes = max(1, $statistics->sum('minutes'));
        $assists = $statistics->sum('assists');
        $turnovers = $statistics->sum('turnovers');

        PlayerObjectiveAssessment::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'stamina' => $this->score($statistics->avg('minutes'), 40),
                'passing' => $this->ratio($assists, $assists + $turnovers),
                'close_range_shooting' => $this->shooting($statistics, 'close'),
                'mid_range_shooting' => $this->shooting($statistics, 'mid'),
                'long_range_shooting' => $this->shooting($statistics, 'three'),
                'defense' => $this->score(
                    ($statistics->sum('steals') + $statistics->sum('blocks')) * 40 / $minutes,
                    6,
                ),
                'rebounding' => $this->score(
                    ($statistics->sum('offensive_rebounds') + $statistics->sum('defensive_rebounds')) * 40 / $minutes,
                    15,
                ),
                'games_count' => $gamesCount,
                'confidence' => min(1, $gamesCount / 10),
                'formula_version' => self::FORMULA_VERSION,
                'calculated_at' => now(),
            ],
        );
    }

    private function shooting($statistics, string $range): ?float
    {
        $attempted = $statistics->sum($range.'_attempted');

        return $attempted > 0 ? $this->ratio($statistics->sum($range.'_made'), $attempted) : null;
    }

    private function ratio(float|int $value, float|int $total): ?float
    {
        return $total > 0 ? round(min(10, max(0, ($value / $total) * 10)), 2) : null;
    }

    private function score(float|int|null $value, float|int $maximum): ?float
    {
        return $value === null ? null : round(min(10, max(0, ($value / $maximum) * 10)), 2);
    }
}
