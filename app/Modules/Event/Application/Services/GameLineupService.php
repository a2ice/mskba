<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\GameLineupRoleEnum;
use App\Modules\Event\Domain\Enums\GameRosterStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class GameLineupService
{
    public function __construct(private readonly EventManagementAccess $access) {}

    /**
     * @param array{A: array<int, int>, B: array<int, int>} $starterUserIds
     * @param array{A: int|null, B: int|null} $captainUserIds
     */
    public function update(Event $game, Actor $actor, array $starterUserIds, array $captainUserIds): void
    {
        DB::transaction(function () use ($game, $actor, $starterUserIds, $captainUserIds): void {
            $aggregate = $game->parent_event_id !== null
                ? Event::query()->lockForUpdate()->findOrFail($game->parent_event_id)
                : Event::query()->lockForUpdate()->findOrFail($game->id);
            $this->access->assertAllows(
                $aggregate,
                $actor,
                EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_ROSTER,
            );

            $lockedGame = $aggregate->id === $game->id
                ? $aggregate
                : Event::query()->lockForUpdate()->findOrFail($game->id);
            if ($lockedGame->actual_started_at !== null) {
                throw new InvalidArgumentException('После начала игры стартовый состав и капитана изменять нельзя.');
            }

            $detail = $lockedGame->gameDetail()->lockForUpdate()->firstOrFail();
            $sides = $lockedGame->gameSides()->orderBy('slot')->lockForUpdate()->get()->keyBy('slot');
            if (! $sides->has('A') || ! $sides->has('B')) {
                throw new InvalidArgumentException('Для игры не настроены две стороны.');
            }

            foreach (['A', 'B'] as $slot) {
                $entries = $lockedGame->gameRosterEntries()
                    ->where('game_side_id', $sides[$slot]->id)
                    ->where('status', GameRosterStatusEnum::SELECTED->value)
                    ->lockForUpdate()
                    ->get();
                $this->applySideSelection(
                    $entries,
                    collect($starterUserIds[$slot] ?? [])->map(fn ($id) => (int) $id),
                    isset($captainUserIds[$slot]) ? (int) $captainUserIds[$slot] : null,
                    $slot === 'A' ? (int) $detail->side_a_size : (int) $detail->side_b_size,
                );
            }
        });
    }

    public function prepareAndLockForStart(Event $game): void
    {
        $detail = $game->gameDetail()->lockForUpdate()->firstOrFail();
        $sides = $game->gameSides()->orderBy('slot')->lockForUpdate()->get()->keyBy('slot');
        if (! $sides->has('A') || ! $sides->has('B')) {
            throw new InvalidArgumentException('Для игры не настроены две стороны.');
        }

        foreach (['A', 'B'] as $slot) {
            $entries = $game->gameRosterEntries()
                ->where('game_side_id', $sides[$slot]->id)
                ->where('status', GameRosterStatusEnum::SELECTED->value)
                ->orderByDesc('is_captain')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $required = $slot === 'A' ? (int) $detail->side_a_size : (int) $detail->side_b_size;
            if ($entries->count() < $required) {
                throw new InvalidArgumentException("Для стороны {$slot} недостаточно игроков для стартового состава.");
            }

            $starters = $entries->where('lineup_role', GameLineupRoleEnum::STARTER);
            if ($starters->count() > $required) {
                throw new InvalidArgumentException("Для стороны {$slot} выбрано слишком много стартовых игроков.");
            }

            if ($starters->count() < $required) {
                $need = $required - $starters->count();
                $entries->where('lineup_role', '!=', GameLineupRoleEnum::STARTER)
                    ->take($need)
                    ->each(fn ($entry) => $entry->update(['lineup_role' => GameLineupRoleEnum::STARTER]));
                $entries = $game->gameRosterEntries()
                    ->where('game_side_id', $sides[$slot]->id)
                    ->where('status', GameRosterStatusEnum::SELECTED->value)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            $captains = $entries->where('is_captain', true);
            if ($captains->count() > 1) {
                throw new InvalidArgumentException("Для стороны {$slot} может быть выбран только один капитан.");
            }
            if ($captains->isEmpty()) {
                $captain = $entries->firstWhere('lineup_role', GameLineupRoleEnum::STARTER) ?? $entries->first();
                $captain?->update(['is_captain' => true]);
            }

            $game->gameRosterEntries()
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
