<?php

namespace App\Modules\Tournament\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Tournament\Domain\Models\TournamentEntry;
use App\Modules\Tournament\Domain\Models\TournamentMatch;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TournamentMatchService
{
    public function __construct(private readonly TournamentAccess $access) {}

    public function create(Tournament $tournament, TournamentEntry $entryA, TournamentEntry $entryB, ?int $round, Actor $actor): TournamentMatch
    {
        return DB::transaction(function () use ($tournament, $entryA, $entryB, $round, $actor): TournamentMatch {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($locked, $actor, TournamentPermissionEnum::MANAGE_GAMES);
            if ($locked->status === TournamentStatusEnum::CANCELLED) {
                throw new InvalidArgumentException('В отменённый турнир нельзя добавлять матчи.');
            }
            $entries = $locked->entries()->whereKey([$entryA->id, $entryB->id])->lockForUpdate()->get();
            if ($entryA->is($entryB) || $entries->count() !== 2 || $entries->contains(fn (TournamentEntry $entry) => $entry->status !== TournamentEntryStatusEnum::ACTIVE)) {
                throw new InvalidArgumentException('Матч должен содержать две разные активные стороны этого турнира.');
            }

            return $locked->matches()->create([
                'entry_a_id' => $entryA->id,
                'entry_b_id' => $entryB->id,
                'round' => $round,
                'sequence' => ((int) $locked->matches()->withTrashed()->max('sequence')) + 1,
            ]);
        });
    }

    /** @param list<int> $orderedIds */
    public function reorder(Tournament $tournament, array $orderedIds, Actor $actor): void
    {
        DB::transaction(function () use ($tournament, $orderedIds, $actor): void {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($locked, $actor, TournamentPermissionEnum::MANAGE_GAMES);
            $matches = $locked->matches()->lockForUpdate()->get()->keyBy('id');
            if (array_values(array_unique($orderedIds)) !== $orderedIds
                || collect($orderedIds)->sort()->values()->all() !== $matches->keys()->sort()->values()->all()) {
                throw new InvalidArgumentException('Порядок должен содержать все матчи ровно по одному разу.');
            }
            $this->replaceSequence($matches, $orderedIds);
        });
    }

    public function delete(Tournament $tournament, TournamentMatch $match, Actor $actor): void
    {
        DB::transaction(function () use ($tournament, $match, $actor): void {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($locked, $actor, TournamentPermissionEnum::MANAGE_GAMES);
            $lockedMatch = $locked->matches()->whereKey($match->id)->lockForUpdate()->firstOrFail();
            if ($lockedMatch->game_id !== null) {
                throw new InvalidArgumentException('Назначенный на Game матч нельзя удалить.');
            }
            // Unscheduled match is only a draft structure and owns no sports history.
            // Physical deletion also releases its unique sequence for compaction.
            $lockedMatch->forceDelete();
            $matches = $locked->matches()->lockForUpdate()->get()->keyBy('id');
            $this->replaceSequence($matches, $matches->keys()->values()->all());
        });
    }

    private function replaceSequence($matches, array $orderedIds): void
    {
        $offset = $matches->count() + (int) $matches->max('sequence') + 1;
        foreach ($matches as $match) {
            $match->forceFill(['sequence' => $match->sequence + $offset])->save();
        }
        foreach ($orderedIds as $position => $id) {
            $matches->get($id)->forceFill(['sequence' => $position + 1])->save();
        }
    }
}
