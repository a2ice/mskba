<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\GameActionTypeEnum;
use App\Modules\Event\Domain\Models\Game;
use Illuminate\Support\Collection;

final class GamePeriodStatisticsBuilder
{
    /** @return Collection<int, array{number:int,status:string,score_a:int|null,score_b:int|null,players:array<int, array<string,int>>}> */
    public function build(Game $game): Collection
    {
        $previousA = 0;
        $previousB = 0;

        return $game->periods->map(function ($period) use (&$previousA, &$previousB): array {
            $players = [];
            foreach ($period->actions as $action) {
                if ($action->user_id === null) {
                    continue;
                }
                $userId = (int) $action->user_id;
                $players[$userId] ??= [];
                foreach ($this->increments($action->type, $action->payload ?? []) as $field => $value) {
                    $players[$userId][$field] = ($players[$userId][$field] ?? 0) + $value;
                }
                if ($action->points !== null) {
                    $players[$userId]['points'] = ($players[$userId]['points'] ?? 0) + $action->points;
                }
            }

            $scoreA = $period->side_a_score === null ? null : max(0, $period->side_a_score - $previousA);
            $scoreB = $period->side_b_score === null ? null : max(0, $period->side_b_score - $previousB);
            $previousA = $period->side_a_score ?? $previousA;
            $previousB = $period->side_b_score ?? $previousB;

            return [
                'number' => $period->number,
                'status' => $period->status->value,
                'score_a' => $scoreA,
                'score_b' => $scoreB,
                'players' => $players,
            ];
        });
    }

    /** @param array<string, mixed> $payload @return array<string, int> */
    private function increments(GameActionTypeEnum $type, array $payload): array
    {
        if (in_array($type, [GameActionTypeEnum::SHOT_MADE, GameActionTypeEnum::SHOT_MISSED], true)) {
            $range = (string) ($payload['range'] ?? '');
            if (! in_array($range, ['close', 'mid', 'three', 'free_throw'], true)) {
                return [];
            }

            return [$range.'_attempted' => 1] + ($type === GameActionTypeEnum::SHOT_MADE ? [$range.'_made' => 1] : []);
        }

        $field = match ($type) {
            GameActionTypeEnum::ASSIST => 'assists',
            GameActionTypeEnum::REBOUND => (string) ($payload['field'] ?? 'defensive_rebounds'),
            GameActionTypeEnum::STEAL => 'steals',
            GameActionTypeEnum::FOUL => 'fouls',
            GameActionTypeEnum::STATISTICS_CORRECTION => (string) ($payload['field'] ?? ''),
            default => '',
        };

        return $field === '' ? [] : [$field => 1];
    }
}
