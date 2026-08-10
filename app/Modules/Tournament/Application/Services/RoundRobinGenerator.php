<?php

namespace App\Modules\Tournament\Application\Services;

use InvalidArgumentException;

final class RoundRobinGenerator
{
    /**
     * @param  list<int>  $entryIds
     * @return list<array{round:int, matches:list<array{entry_a_id:int, entry_b_id:int}>}>
     */
    public function generate(array $entryIds, int $legs): array
    {
        if (count($entryIds) < 2 || ! in_array($legs, [1, 2], true)) {
            throw new InvalidArgumentException('Для круговой схемы нужны минимум две стороны и один или два круга.');
        }

        $participants = array_values($entryIds);
        if (count($participants) % 2 !== 0) {
            $participants[] = null;
        }

        $rounds = [];
        $roundsPerLeg = count($participants) - 1;
        for ($round = 1; $round <= $roundsPerLeg; $round++) {
            $matches = [];
            $last = count($participants) - 1;
            for ($index = 0; $index < count($participants) / 2; $index++) {
                $entryA = $participants[$index];
                $entryB = $participants[$last - $index];
                if ($entryA === null || $entryB === null) {
                    continue;
                }
                if (($round + $index) % 2 === 0) {
                    [$entryA, $entryB] = [$entryB, $entryA];
                }
                $matches[] = ['entry_a_id' => $entryA, 'entry_b_id' => $entryB];
            }
            $rounds[] = ['round' => $round, 'matches' => $matches];
            $participants = [$participants[0], $participants[$last], ...array_slice($participants, 1, $last - 1)];
        }

        if ($legs === 2) {
            foreach (array_slice($rounds, 0, $roundsPerLeg) as $round) {
                $rounds[] = [
                    'round' => $round['round'] + $roundsPerLeg,
                    'matches' => array_map(fn (array $match): array => [
                        'entry_a_id' => $match['entry_b_id'],
                        'entry_b_id' => $match['entry_a_id'],
                    ], $round['matches']),
                ];
            }
        }

        return $rounds;
    }
}
