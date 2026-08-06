<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\GameLineupRoleEnum;
use App\Modules\Event\Domain\Enums\GameRosterStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class GameLineupService
{
    public function __construct(private readonly EventManagementAccess $access) {}

    /**
     * @param  array{A: array<int, int>, B: array<int, int>}  $starterUserIds
     * @param  array{A: int|null, B: int|null}  $captainUserIds
     */
    public function update(Game $game, Actor $actor, array $starterUserIds, array $captainUserIds): void
    {
        DB::transaction(function () use ($game, $actor, $starterUserIds, $captainUserIds): void {
            $event = Event::query()->lockForUpdate()->findOrFail($game->event_id);
            $this->access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_ROSTER);
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);

            if ($lockedGame->actual_started_at !== null) {
                throw new InvalidArgumentException('После начала игры стартовый состав и капитана изменять нельзя.');
            }

            $sides = $lockedGame->sides()->orderBy('slot')->lockForUpdate()->get()->keyBy('slot');
            if (! $sides->has('A') || ! $sides->has('B')) {
                throw new InvalidArgumentException('Для игры не настроены две стороны.');
            }

            foreach (['A', 'B'] as $slot) {
                $entries = $lockedGame->rosterEntries()
                    ->where('game_side_id', $sides[$slot]->id)
                    ->where('status', GameRosterStatusEnum::SELECTED->value)
                    ->lockForUpdate()
                    ->get();
                $this->applySideSelection(
                    $entries,
                    collect($starterUserIds[$slot] ?? [])->map(fn ($id) => (int) $id),
                    isset($captainUserIds[$slot]) ? (int) $captainUserIds[$slot] : null,
                    $slot === 'A' ? (int) $lockedGame->side_a_size : (int) $lockedGame->side_b_size,
                );
            }
        }, 3);
    }

    public function prepareAndLockForStart(Game $game): void
    {
        $sides = $game->sides()->orderBy('slot')->lockForUpdate()->get()->keyBy('slot');
        if (! $sides->has('A') || ! $sides->has('B')) {
            throw new InvalidArgumentException('Для игры не настроены две стороны.');
        }

        foreach (['A', 'B'] as $slot) {
            $entries = $game->rosterEntries()
                ->where('game_side_id', $sides[$slot]->id)
                ->where('status', GameRosterStatusEnum::SELECTED->value)
                ->orderByDesc('is_captain')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $required = $slot === 'A' ? (int) $game->side_a_size : (int) $game->side_b_size;
            if ($entries->count() < $required) {
                throw new InvalidArgumentException("Для стороны {$slot} недостаточно игроков для стартового состава.");
            }

            $starters = $entries->where('lineup_role', GameLineupRoleEnum::STARTER);
            if ($starters->count() > $required) {
                throw new InvalidArgumentException("Для стороны {$slot} выбрано слишком много стартовых игроков.");
            }
            if ($starters->count() < $required) {
                $entries->where('lineup_role', '!=', GameLineupRoleEnum::STARTER)
                    ->take($required - $starters->count())
                    ->each(fn ($entry) => $entry->update(['lineup_role' => GameLineupRoleEnum::STARTER]));
                $entries = $game->rosterEntries()
                    ->where('game_side_id', $sides[$slot]->id)
                    ->where('status', GameRosterStatusEnum::SELECTED->value)
                    ->orderByDesc('is_captain')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            $captains = $entries->where('is_captain', true);
            if ($captains->count() > 1) {
                throw new InvalidArgumentException("Для стороны {$slot} может быть выбран только один капитан.");
            }
            if ($captains->isEmpty()) {
                ($entries->firstWhere('lineup_role', GameLineupRoleEnum::STARTER) ?? $entries->first())
                    ?->update(['is_captain' => true]);
            }

            $game->rosterEntries()
                ->where('game_side_id', $sides[$slot]->id)
                ->where('status', GameRosterStatusEnum::SELECTED->value)
                ->update(['locked_at' => now()]);
        }
    }

    /** @param Collection<int, mixed> $entries */
    private function applySideSelection(Collection $entries, Collection $starterIds, ?int $captainId, int $required): void
    {
        $availableIds = $entries->pluck('user_id')->map(fn ($id) => (int) $id);
        if ($starterIds->unique()->count() !== $starterIds->count()
            || $starterIds->diff($availableIds)->isNotEmpty()) {
            throw new InvalidArgumentException('Стартовый состав содержит недоступного или повторяющегося игрока.');
        }
        if ($starterIds->count() !== $required) {
            throw new InvalidArgumentException("В стартовом составе должно быть ровно {$required} игроков.");
        }
        if ($captainId === null || ! $availableIds->contains($captainId)) {
            throw new InvalidArgumentException('Капитан должен входить в состав этой стороны.');
        }

        foreach ($entries as $entry) {
            $entry->update([
                'lineup_role' => $starterIds->contains((int) $entry->user_id)
                    ? GameLineupRoleEnum::STARTER
                    : GameLineupRoleEnum::BENCH,
                'is_captain' => (int) $entry->user_id === $captainId,
            ]);
        }
    }
}
