<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\GameLifecycleService;
use App\Modules\Event\Application\Services\LegacyGameRouteResolver;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
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
        LegacyGameRouteResolver $games,
    ): JsonResponse {
        $game = $this->findGame($games, $event);
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
        $usesNestedRoute = $nestedGame !== null;

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
            'start_url' => $usesNestedRoute
                ? route('events.games.start', [$nestedEvent, $nestedGame])
                : route('events.game.lifecycle.start', $game->legacyEvent->routeIdentifier()),
            'end_url' => $usesNestedRoute
                ? route('events.games.end', [$nestedEvent, $nestedGame])
                : route('events.game.lifecycle.end', $game->legacyEvent->routeIdentifier()),
            'lineup_update_url' => $usesNestedRoute
                ? route('events.games.lineup.update', [$nestedEvent, $nestedGame])
                : route('events.game.lineup.update', $game->legacyEvent->routeIdentifier()),
            'roster' => $roster,
        ]);
    }

    public function start(
        Request $request,
        string $event,
        CurrentActorResolver $actors,
        GameLifecycleService $lifecycle,
        LegacyGameRouteResolver $games,
    ): JsonResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $game = $lifecycle->start($games->resolve($event), $actor);
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
        LegacyGameRouteResolver $games,
    ): JsonResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        try {
            $game = $lifecycle->end($games->resolve($event), $actor);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Фактическое проведение игры завершено. Проверьте и подтвердите результат.',
            'actual_ended_at' => $game->actual_ended_at?->toIso8601String(),
        ]);
    }

    private function findGame(LegacyGameRouteResolver $games, string $identifier): Game
    {
        return $games->resolve($identifier)->load([
            'event',
            'legacyEvent',
            'sides',
            'rosterEntries.user.profile',
        ]);
    }
}
