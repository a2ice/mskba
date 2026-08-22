<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GamePeriodStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\GamePeriod;
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

            $isStandalone = $event->type === EventTypeEnum::GAME
                && (int) $event->primary_game_id === (int) $lockedGame->id
                && $lockedGame->recruitment_mode !== null;
            if ($isStandalone && $lockedGame->sides_confirmed_at === null) {
                throw new InvalidArgumentException('Перед началом игры утвердите обе стороны.');
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
            if ($lockedGame->timing_mode === GameTimingModeEnum::PERIODS) {
                $firstPeriod = $lockedGame->periods()->where('number', 1)->lockForUpdate()->firstOrFail();
                $firstPeriod->update([
                    'status' => GamePeriodStatusEnum::IN_PROGRESS,
                    'actual_started_at' => now(),
                    'started_by_actor_id' => $actor->id,
                ]);
            }

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
            if ($lockedGame->timing_mode === GameTimingModeEnum::PERIODS) {
                $activePeriod = $lockedGame->periods()
                    ->where('status', GamePeriodStatusEnum::IN_PROGRESS->value)
                    ->lockForUpdate()
                    ->first();
                if ($activePeriod === null || $activePeriod->number !== (int) $lockedGame->periods_count) {
                    throw new InvalidArgumentException('Сначала завершите все периоды игры.');
                }
                $this->completePeriod($lockedGame, $activePeriod, $actor);
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

    public function endPeriod(Game $game, Actor $actor): Game
    {
        $game = DB::transaction(function () use ($game, $actor): Game {
            $event = Event::query()->lockForUpdate()->findOrFail($game->event_id);
            $this->access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            if ($lockedGame->timing_mode !== GameTimingModeEnum::PERIODS || $lockedGame->actual_ended_at !== null) {
                throw new InvalidArgumentException('У этой игры нет активного периода.');
            }
            $period = $lockedGame->periods()
                ->where('status', GamePeriodStatusEnum::IN_PROGRESS->value)
                ->lockForUpdate()
                ->first();
            if ($period === null) {
                throw new InvalidArgumentException('Сначала начните период.');
            }
            if ($period->number >= (int) $lockedGame->periods_count) {
                throw new InvalidArgumentException('Последний период завершается вместе с игрой.');
            }
            $this->completePeriod($lockedGame, $period, $actor);

            return $lockedGame->fresh();
        }, 3);
        event(new EventChanged($game->event_id));

        return $game;
    }

    public function endEarly(Game $game, Actor $actor, string $comment): Game
    {
        $game = DB::transaction(function () use ($game, $actor, $comment): Game {
            $event = Event::query()->lockForUpdate()->findOrFail($game->event_id);
            $this->access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);

            if ($lockedGame->timing_mode !== GameTimingModeEnum::PERIODS
                || $lockedGame->actual_started_at === null
                || $lockedGame->actual_ended_at !== null
                || $lockedGame->status === GameStatusEnum::CANCELLED) {
                throw new InvalidArgumentException('Эту игру нельзя завершить досрочно.');
            }

            $activePeriod = $lockedGame->periods()
                ->where('status', GamePeriodStatusEnum::IN_PROGRESS->value)
                ->lockForUpdate()
                ->first();
            if ($activePeriod === null || $activePeriod->number >= (int) $lockedGame->periods_count) {
                throw new InvalidArgumentException('Досрочное завершение доступно только до последнего активного периода.');
            }

            $this->completePeriod($lockedGame, $activePeriod, $actor);
            $lockedGame->update([
                'status' => GameStatusEnum::AWAITING_RESULT,
                'actual_ended_at' => now(),
                'actual_ended_by_actor_id' => $actor->id,
                'ended_early' => true,
                'status_comment' => trim($comment),
                'statistics_status' => $lockedGame->statistics_status === GameStatisticsStatusEnum::ENTERING
                    ? GameStatisticsStatusEnum::READY
                    : $lockedGame->statistics_status,
            ]);

            return $lockedGame->fresh();
        }, 3);

        event(new EventChanged($game->event_id));

        return $game;
    }

    public function startNextPeriod(Game $game, Actor $actor): Game
    {
        $game = DB::transaction(function () use ($game, $actor): Game {
            $event = Event::query()->lockForUpdate()->findOrFail($game->event_id);
            $this->access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            if ($lockedGame->timing_mode !== GameTimingModeEnum::PERIODS
                || $lockedGame->actual_started_at === null
                || $lockedGame->actual_ended_at !== null) {
                throw new InvalidArgumentException('Следующий период сейчас начать нельзя.');
            }
            if ($lockedGame->periods()->where('status', GamePeriodStatusEnum::IN_PROGRESS->value)->exists()) {
                throw new InvalidArgumentException('Сначала завершите текущий период.');
            }
            $completed = $lockedGame->periods()
                ->where('status', GamePeriodStatusEnum::COMPLETED->value)
                ->count();
            $next = $lockedGame->periods()->where('number', $completed + 1)->lockForUpdate()->first();
            if ($next === null || $next->status !== GamePeriodStatusEnum::SCHEDULED) {
                throw new InvalidArgumentException('Все периоды уже проведены.');
            }
            $next->update([
                'status' => GamePeriodStatusEnum::IN_PROGRESS,
                'actual_started_at' => now(),
                'started_by_actor_id' => $actor->id,
            ]);

            return $lockedGame->fresh();
        }, 3);
        event(new EventChanged($game->event_id));

        return $game;
    }

    private function completePeriod(Game $game, GamePeriod $period, Actor $actor): void
    {
        $sides = $game->sides()->lockForUpdate()->get()->keyBy('slot');
        $period->update([
            'status' => GamePeriodStatusEnum::COMPLETED,
            'actual_ended_at' => now(),
            'ended_by_actor_id' => $actor->id,
            'side_a_score' => (int) ($sides->get('A')?->score ?? 0),
            'side_b_score' => (int) ($sides->get('B')?->score ?? 0),
        ]);
    }
}
