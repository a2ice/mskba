<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\GameLifecycleService;
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
            'start_url' => route('events.games.start', [$nestedEvent, $nestedGame]),
            'end_url' => route('events.games.end', [$nestedEvent, $nestedGame]),
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
        ]);
    }
}
