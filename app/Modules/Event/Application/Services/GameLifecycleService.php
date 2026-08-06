<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class GameLifecycleService
{
    public function __construct(
        private readonly EventManagementAccess $access,
        private readonly GameLineupService $lineups,
    ) {}

    public function start(Game $game, Actor $actor): Game
    {
        $game = DB::transaction(function () use ($game, $actor): Game {
            $event = Event::query()->lockForUpdate()->findOrFail($game->event_id);
            $this->access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);

            if ($lockedGame->status === GameStatusEnum::CANCELLED) {
                throw new InvalidArgumentException('Отменённую игру начать нельзя.');
            }
            if ($lockedGame->status === GameStatusEnum::COMPLETED) {
                throw new InvalidArgumentException('Завершённую игру начать нельзя.');
            }
            if ($lockedGame->actual_started_at !== null) {
                throw new InvalidArgumentException('Игра уже началась.');
            }
            if ($lockedGame->statistics_status === GameStatisticsStatusEnum::CONFIRMED) {
                throw new InvalidArgumentException('Игра с подтверждённым результатом уже закрыта.');
            }
            if ($lockedGame->sides()->count() !== 2 || $lockedGame->rosterEntries()->count() === 0) {
                throw new InvalidArgumentException('Перед началом игры необходимо сформировать команды и составы.');
            }

            $this->lineups->prepareAndLockForStart($lockedGame);
            $lockedGame->update([
                'status' => GameStatusEnum::IN_PROGRESS,
                'actual_started_at' => now(),
                'actual_started_by_actor_id' => $actor->id,
                'statistics_status' => $lockedGame->statistics_status === GameStatisticsStatusEnum::NOT_STARTED
                    ? GameStatisticsStatusEnum::ENTERING
                    : $lockedGame->statistics_status,
            ]);

            return $lockedGame->fresh();
        }, 3);

        event(new EventChanged($game->event_id));

        return $game;
    }

    public function end(Game $game, Actor $actor): Game
    {
        $game = DB::transaction(function () use ($game, $actor): Game {
            $event = Event::query()->lockForUpdate()->findOrFail($game->event_id);
            $this->access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);

            if ($lockedGame->status === GameStatusEnum::CANCELLED) {
                throw new InvalidArgumentException('Отменённую игру закончить нельзя.');
            }
            if ($lockedGame->actual_started_at === null) {
                throw new InvalidArgumentException('Сначала необходимо начать игру.');
            }
            if ($lockedGame->actual_ended_at !== null) {
                throw new InvalidArgumentException('Игра уже закончена.');
            }
            if ($lockedGame->statistics_status === GameStatisticsStatusEnum::CONFIRMED) {
                throw new InvalidArgumentException('Результат игры уже подтверждён.');
            }

            $lockedGame->update([
                'status' => GameStatusEnum::AWAITING_RESULT,
                'actual_ended_at' => now(),
                'actual_ended_by_actor_id' => $actor->id,
                'statistics_status' => $lockedGame->statistics_status === GameStatisticsStatusEnum::ENTERING
                    ? GameStatisticsStatusEnum::READY
                    : $lockedGame->statistics_status,
            ]);

            return $lockedGame->fresh();
        }, 3);

        event(new EventChanged($game->event_id));

        return $game;
    }
}
