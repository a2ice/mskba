<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\GameLifecycleService;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GamePeriodStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class GameLifecycleController extends Controller
{
    public function show(
        Request $request,
        string $event,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
    ): JsonResponse {
        $game = $this->findGame($request, $event);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        $canComplete = $access->allows($game->event, $actor, EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);
        $canManageStatistics = $access->allows($game->event, $actor, EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_STATISTICS);
        $canManageScore = $access->allows($game->event, $actor, EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_SCORE);
        $canManageRoster = $access->allows($game->event, $actor, EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_ROSTER);

        $cancelled = $game->status === GameStatusEnum::CANCELLED;
        $completed = $game->status === GameStatusEnum::COMPLETED
            || $game->statistics_status === GameStatisticsStatusEnum::CONFIRMED;
        $started = $game->actual_started_at !== null;
        $ended = $game->actual_ended_at !== null;
        $requiresSideConfirmation = $game->event->type === EventTypeEnum::GAME
            && (int) $game->event->primary_game_id === (int) $game->id
            && $game->recruitment_mode !== null;
        $sidesReady = ! $requiresSideConfirmation || $game->sides_confirmed_at !== null;

        $roster = $game->sides->mapWithKeys(function ($side) use ($game): array {
            $entries = $game->rosterEntries
                ->where('game_side_id', $side->id)
                ->values()
                ->map(function ($entry): array {
                    $profile = $entry->user->profile;
                    $name = trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name])))
                        ?: $entry->user->username
                        ?: 'Пользователь #'.$entry->user_id;

                    return [
                        'user_id' => (int) $entry->user_id,
                        'name' => $name,
                        'lineup_role' => $entry->lineup_role->value,
                        'is_captain' => (bool) $entry->is_captain,
                    ];
                });

            return [$side->slot => [
                'name' => $side->display_name,
                'required_starters' => $side->slot === 'A'
                    ? (int) $game->side_a_size
                    : (int) $game->side_b_size,
                'players' => $entries,
            ]];
        });

        $nestedEvent = $request->route('event');
        $nestedGame = $request->route('game');
        $periods = $game->periods->map(fn ($period): array => [
            'number' => $period->number,
            'status' => $period->status->value,
            'label' => $period->status->label(),
            'side_a_score' => $period->side_a_score,
            'side_b_score' => $period->side_b_score,
        ])->values();
        $activePeriod = $game->periods->first(fn ($period) => $period->status === GamePeriodStatusEnum::IN_PROGRESS);
        $completedPeriods = $game->periods->where('status', GamePeriodStatusEnum::COMPLETED)->count();
        $usesPeriods = $game->timing_mode === GameTimingModeEnum::PERIODS;

        return response()->json([
            'started' => $started,
            'ended' => $ended,
            'cancelled' => $cancelled,
            'completed' => $completed,
            'sides_confirmed' => $game->sides_confirmed_at !== null,
            'actual_started_at' => $game->actual_started_at?->toIso8601String(),
            'actual_ended_at' => $game->actual_ended_at?->toIso8601String(),
            'can_start' => $canComplete && $sidesReady && ! $started && ! $cancelled && ! $completed,
            'can_end' => $canComplete && $started && ! $ended && ! $cancelled && ! $completed
                && (! $usesPeriods || $activePeriod?->number === (int) $game->periods_count),
            'can_end_period' => $canComplete && $usesPeriods && $activePeriod !== null
                && $activePeriod->number < (int) $game->periods_count,
            'can_end_early' => $canComplete && $usesPeriods && $started && ! $ended && ! $cancelled && ! $completed
                && $activePeriod !== null && $activePeriod->number < (int) $game->periods_count,
            'can_start_next_period' => $canComplete && $usesPeriods && $started && ! $ended
                && $activePeriod === null && $completedPeriods < (int) $game->periods_count,
            'can_enter_statistics' => $canManageStatistics && $started && ! $ended && ! $cancelled && ! $completed
                && (! $usesPeriods || $activePeriod !== null),
            'can_manage_score' => $canManageScore && $started && ! $ended && ! $cancelled && ! $completed
                && (! $usesPeriods || $activePeriod !== null),
            'can_manage_lineup' => $canManageRoster && ! $started && ! $cancelled && ! $completed,
            'can_confirm_result' => $canComplete && $canManageStatistics && $ended && ! $cancelled && ! $completed,
            'start_url' => route('events.games.start', [$nestedEvent, $nestedGame]),
            'end_url' => route('events.games.end', [$nestedEvent, $nestedGame]),
            'end_period_url' => route('events.games.periods.end', [$nestedEvent, $nestedGame]),
            'end_early_url' => route('events.games.end-early', [$nestedEvent, $nestedGame]),
            'start_next_period_url' => route('events.games.periods.start-next', [$nestedEvent, $nestedGame]),
            'timing_mode' => $game->timing_mode->value,
            'periods_count' => $game->periods_count,
            'active_period' => $activePeriod?->number,
            'periods' => $periods,
            'lineup_update_url' => route('events.games.lineup.update', [$nestedEvent, $nestedGame]),
            'roster' => $roster,
        ]);
    }

    public function start(
        Request $request,
        string $event,
        CurrentActorResolver $actors,
        GameLifecycleService $lifecycle,
    ): JsonResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $game = $lifecycle->start($this->findGame($request, $event), $actor);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Игра началась.',
            'actual_started_at' => $game->actual_started_at?->toIso8601String(),
        ]);
    }

    public function end(
        Request $request,
        string $event,
        CurrentActorResolver $actors,
        GameLifecycleService $lifecycle,
    ): JsonResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $game = $lifecycle->end($this->findGame($request, $event), $actor);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Фактическое проведение игры завершено. Проверьте и подтвердите результат.',
            'actual_ended_at' => $game->actual_ended_at?->toIso8601String(),
        ]);
    }

    public function endPeriod(
        Request $request,
        string $event,
        CurrentActorResolver $actors,
        GameLifecycleService $lifecycle,
    ): JsonResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        try {
            $game = $lifecycle->endPeriod($this->findGame($request, $event), $actor);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Период завершён.', 'game_id' => $game->id]);
    }

    public function endEarly(
        Request $request,
        string $event,
        CurrentActorResolver $actors,
        GameLifecycleService $lifecycle,
    ): JsonResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $data = $request->validate([
            'comment' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        try {
            $game = $lifecycle->endEarly($this->findGame($request, $event), $actor, $data['comment']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Игра завершена досрочно. Проверьте и подтвердите результат.',
            'actual_ended_at' => $game->actual_ended_at?->toIso8601String(),
        ]);
    }

    public function startNextPeriod(
        Request $request,
        string $event,
        CurrentActorResolver $actors,
        GameLifecycleService $lifecycle,
    ): JsonResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        try {
            $game = $lifecycle->startNextPeriod($this->findGame($request, $event), $actor);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Следующий период начался.', 'game_id' => $game->id]);
    }

    private function findGame(Request $request, string $identifier): Game
    {
        $gameId = $request->route('game');
        abort_if($gameId === null, 404);
        $game = Game::query()
            ->whereKey((int) $gameId)
            ->whereHas('event', fn ($query) => $query->whereRouteIdentifier($identifier))
            ->firstOrFail();

        return $game->load([
            'event',
            'sides',
            'rosterEntries.user.profile',
            'periods',
        ]);
    }
}
