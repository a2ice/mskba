<?php

namespace App\Modules\Tournament\Application\Services;

use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;

final class TournamentStandingsService
{
    /** @return list<array<string, int|string>> */
    public function build(Tournament $tournament): array
    {
        $rows = $tournament->entries->mapWithKeys(fn ($entry): array => [$entry->id => [
            'entry_id' => $entry->id,
            'name' => $entry->name,
            'played' => 0,
            'wins' => 0,
            'draws' => 0,
            'losses' => 0,
            'scored' => 0,
            'conceded' => 0,
            'difference' => 0,
            'points' => 0,
        ]]);

        foreach ($tournament->matches as $match) {
            $game = $match->game;
            if ($game === null || $game->statistics_status !== GameStatisticsStatusEnum::CONFIRMED) {
                continue;
            }
            $sides = $game->sides->keyBy('slot');
            if (! $rows->has($match->entry_a_id) || ! $rows->has($match->entry_b_id) || ! $sides->has('A') || ! $sides->has('B')) {
                continue;
            }
            $scoreA = (int) $sides['A']->score;
            $scoreB = (int) $sides['B']->score;
            foreach ([[$match->entry_a_id, $scoreA, $scoreB], [$match->entry_b_id, $scoreB, $scoreA]] as [$entryId, $scored, $conceded]) {
                $row = $rows[$entryId];
                $row['played']++;
                $row['scored'] += $scored;
                $row['conceded'] += $conceded;
                $row['difference'] = $row['scored'] - $row['conceded'];
                if ($scored > $conceded) {
                    $row['wins']++;
                    $row['points'] += 2;
                } elseif ($scored === $conceded) {
                    $row['draws']++;
                    $row['points']++;
                } else {
                    $row['losses']++;
                }
                $rows[$entryId] = $row;
            }
        }

        return $rows->sort(function (array $left, array $right): int {
            foreach (['points', 'wins', 'difference', 'scored'] as $field) {
                if ($left[$field] !== $right[$field]) {
                    return $right[$field] <=> $left[$field];
                }
            }

            return strnatcasecmp($left['name'], $right['name']);
        })->values()->map(fn (array $row, int $position): array => ['position' => $position + 1, ...$row])->all();
    }
}
