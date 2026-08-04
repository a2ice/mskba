<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class GameLifecycleService
{
    public function __construct(
        private readonly EventManagementAccess $access,
        private readonly GameLineupService $lineups,
    ) {}

    public function start(Event $game, Actor $actor): Event
    {
        $game = DB::transaction(function () use ($game, $actor): Event {
            $lockedGame = Event::query()->lockForUpdate()->findOrFail($game->id);
            $this->assertGame($lockedGame);
            $this->access->assertAllows($lockedGame, $actor, EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);

            if ($lockedGame->status === EventStatusEnum::CANCELLED) {
                throw new InvalidArgumentException('Отменённую игру начать нельзя.');
            }

            if ($lockedGame->status === EventStatusEnum::COMPLETED) {
                throw new InvalidArgumentException('Завершённую игру начать нельзя.');
            }

            if ($lockedGame->actual_started_at !== null) {
                throw new InvalidArgumentException('Игра уже началась.');
            }

            $detail = $lockedGame->gameDetail()->lockForUpdate()->firstOrFail();
            if ($detail->statistics_status === GameStatisticsStatusEnum::CONFIRMED) {
                throw new InvalidArgumentException('Игра с подтверждённым результатом уже закрыта.');
            }

            if ($lockedGame->gameSides()->count() !== 2 || $lockedGame->gameRosterEntries()->count() === 0) {
                throw new InvalidArgumentException('Перед началом игры необходимо сформировать команды и составы.');
            }

            $this->lineups->prepareAndLockForStart($lockedGame);

            $lockedGame->update([
                'actual_started_at' => now(),
                'actual_started_by_actor_id' => $actor->id,
            ]);

            if ($detail->statistics_status === GameStatisticsStatusEnum::NOT_STARTED) {
                $detail->update(['statistics_status' => GameStatisticsStatusEnum::ENTERING]);
            }

            return $lockedGame->fresh(['gameDetail']);
        });

        event(new EventChanged($game->id));
        if ($game->parent_event_id !== null) {
            event(new EventChanged((int) $game->parent_event_id));
        }

        return $game;
    }

    public function end(Event $game, Actor $actor): Event
    {
        $game = DB::transaction(function () use ($game, $actor): Event {
            $lockedGame = Event::query()->lockForUpdate()->findOrFail($game->id);
            $this->assertGame($lockedGame);
            $this->access->assertAllows($lockedGame, $actor, EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);

            if ($lockedGame->status === EventStatusEnum::CANCELLED) {
                throw new InvalidArgumentException('Отменённую игру закончить нельзя.');
            }

            if ($lockedGame->actual_started_at === null) {
                throw new InvalidArgumentException('Сначала необходимо начать игру.');
            }

            if ($lockedGame->actual_ended_at !== null) {
                throw new InvalidArgumentException('Игра уже закончена.');
            }

            $detail = $lockedGame->gameDetail()->lockForUpdate()->firstOrFail();
            if ($detail->statistics_status === GameStatisticsStatusEnum::CONFIRMED) {
                throw new InvalidArgumentException('Результат игры уже подтверждён.');
            }

            $lockedGame->update([
                'actual_ended_at' => now(),
                'actual_ended_by_actor_id' => $actor->id,
            ]);

            if ($detail->statistics_status === GameStatisticsStatusEnum::ENTERING) {
                $detail->update(['statistics_status' => GameStatisticsStatusEnum::READY]);
            }

            return $lockedGame->fresh(['gameDetail']);
        });

        event(new EventChanged($game->id));
        if ($game->parent_event_id !== null) {
            event(new EventChanged((int) $game->parent_event_id));
        }

        return $game;
    }

    private function assertGame(Event $event): void
    {
        if ($event->type !== EventTypeEnum::GAME || ! $event->gameDetail()->exists()) {
            throw new InvalidArgumentException('Фактический запуск доступен только для игры.');
        }
    }
}
