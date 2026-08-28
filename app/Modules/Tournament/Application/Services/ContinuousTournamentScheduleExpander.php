<?php

namespace App\Modules\Tournament\Application\Services;

use App\Modules\Tournament\Domain\Enums\TournamentEnrollmentPolicyEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Tournament\Domain\Models\TournamentEntry;
use Illuminate\Support\Facades\DB;

final class ContinuousTournamentScheduleExpander
{
    public function syncForEntry(TournamentEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $tournament = Tournament::query()
                ->whereKey($entry->tournament_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($tournament->enrollment_policy !== TournamentEnrollmentPolicyEnum::CONTINUOUS
                || $tournament->recruitment_mode !== TournamentRecruitmentModeEnum::PREFORMED_TEAMS
                || $tournament->tournament_closed_at !== null) {
                return;
            }

            $newEntry = $tournament->entries()
                ->whereKey($entry->id)
                ->where('status', TournamentEntryStatusEnum::ACTIVE->value)
                ->lockForUpdate()
                ->first();
            if ($newEntry === null) {
                return;
            }

            $others = $tournament->entries()
                ->where('status', TournamentEntryStatusEnum::ACTIVE->value)
                ->whereKeyNot($newEntry->id)
                ->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $sequence = ((int) $tournament->matches()->withTrashed()->max('sequence')) + 1;
            $legs = max(1, min(2, (int) $tournament->round_robin_legs));

            foreach ($others as $other) {
                $existing = $tournament->matches()
                    ->where(function ($query) use ($newEntry, $other): void {
                        $query->where(function ($pair) use ($newEntry, $other): void {
                            $pair->where('entry_a_id', $other->id)->where('entry_b_id', $newEntry->id);
                        })->orWhere(function ($pair) use ($newEntry, $other): void {
                            $pair->where('entry_a_id', $newEntry->id)->where('entry_b_id', $other->id);
                        });
                    })
                    ->orderBy('sequence')
                    ->lockForUpdate()
                    ->get();

                if ($existing->isEmpty()) {
                    $firstA = $other->id < $newEntry->id ? $other->id : $newEntry->id;
                    $firstB = $firstA === $other->id ? $newEntry->id : $other->id;
                    $tournament->matches()->create([
                        'entry_a_id' => $firstA,
                        'entry_b_id' => $firstB,
                        'round' => 1,
                        'sequence' => $sequence++,
                    ]);
                    $existing = $tournament->matches()
                        ->where(function ($query) use ($newEntry, $other): void {
                            $query->where(function ($pair) use ($newEntry, $other): void {
                                $pair->where('entry_a_id', $other->id)->where('entry_b_id', $newEntry->id);
                            })->orWhere(function ($pair) use ($newEntry, $other): void {
                                $pair->where('entry_a_id', $newEntry->id)->where('entry_b_id', $other->id);
                            });
                        })
                        ->orderBy('sequence')
                        ->get();
                }

                if ($legs === 2 && $existing->count() < 2) {
                    $first = $existing->first();
                    $tournament->matches()->create([
                        'entry_a_id' => $first->entry_b_id,
                        'entry_b_id' => $first->entry_a_id,
                        'round' => 2,
                        'sequence' => $sequence++,
                    ]);
                }
            }
        });
    }
}
