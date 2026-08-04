<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\GameLifecycleService;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\GameLineupRoleEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Models\Event;
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
        $game = $this->findGame($event);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        $canComplete = $access->allows($game, $actor, EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);
        $canManageStatistics = $access->allows($game, $actor, EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_STATISTICS);
        $canManageScore = $access->allows($game, $actor, EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_SCORE);
        $canManageRoster = $access->allows($game, $actor, EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_ROSTER);

        $cancelled = $game->status === EventStatusEnum::CANCELLED;
        $completed = $game->status === EventStatusEnum::COMPLETED
            || $game->gameDetail?->statistics_status === GameStatisticsStatusEnum::CONFIRMED;
        $started = $game->actual_started_at !== null;
        $ended = $game->actual_ended_at !== null;

        $roster = $game->gameSides->mapWithKeys(function ($side) use ($game): array {
            $entries = $game->gameRosterEntries
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
                    ? (int) $game->gameDetail->side_a_size
                    : (int) $game->gameDetail->side_b_size,
                'players' => $entries,
            ]];
        });

        return response()->json([
            'started' => $started,
            'ended' => $ended,
            'cancelled' => $cancelled,
            'completed' => $completed,
            'actual_started_at' => $game->actual_started_at?->toIso8601String(),
            'actual_ended_at' => $game->actual_ended_at?->toIso8601String(),
            'can_start' => $canComplete && ! $started && ! $cancelled && ! $completed,
            'can_end' => $canComplete && $started && ! $ended && ! $cancelled && ! $completed,
            'can_enter_statistics' => $canManageStatistics && $started && ! $ended && ! $cancelled && ! $completed,
            'can_manage_score' => $canManageScore && $started && ! $ended && ! $cancelled && ! $completed,
            'can_manage_lineup' => $canManageRoster && ! $started && ! $cancelled && ! $completed,
            'can_confirm_result' => $canComplete && $canManageStatistics && $ended && ! $cancelled && ! $completed,
            'start_url' => route('events.game.lifecycle.start', $game->routeIdentifier()),
            'end_url' => route('events.game.lifecycle.end', $game->routeIdentifier()),
            'lineup_update_url' => route('events.game.lineup.update', $game->routeIdentifier()),
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
            $game = $lifecycle->start($this->findGame($event), $actor);
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
            $game = $lifecycle->end($this->findGame($event), $actor);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Фактическое проведение игры завершено. Проверьте и подтвердите результат.',
            'actual_ended_at' => $game->actual_ended_at?->toIso8601String(),
        ]);
    }

    private function findGame(string $identifier): Event
    {
        return Event::query()
            ->whereRouteIdentifier($identifier)
            ->with([
                'gameDetail',
                'gameSides',
                'gameRosterEntries.user.profile',
            ])
            ->firstOrFail();
    }
}
