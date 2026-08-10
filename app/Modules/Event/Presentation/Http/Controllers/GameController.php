<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\GameManagementService;
use App\Modules\Event\Application\UseCases\CancelEventHandler;
use App\Modules\Event\Application\UseCases\ShowEventHandler;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameActionTypeEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\GamePlayerStatistic;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class GameController extends Controller
{
    public function createMiniGame(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameManagementService $games,
    ): RedirectResponse {
        [$parent, $actor] = $this->managedEvent($request, $event, $events, $actors, $access, EventResponsibilityPermissionEnum::CREATE_MINI_GAME);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'has_scheduled_time' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'required_if:has_scheduled_time,1', 'date_format:H:i'],
            'ends_at' => ['nullable', 'required_if:has_scheduled_time,1', 'date_format:H:i'],
            'side_a_name' => ['nullable', 'string', 'max:80', 'different:side_b_name'],
            'side_b_name' => ['nullable', 'string', 'max:80', 'different:side_a_name'],
            'side_a_size' => ['required', 'integer', 'min:1', 'max:6'],
            'side_b_size' => ['required', 'integer', 'min:1', 'max:5'],
            'scoring_type' => ['nullable', Rule::enum(GameScoringTypeEnum::class)],
            'side_a_user_ids' => ['required', 'array', 'min:1'],
            'side_a_user_ids.*' => ['integer'],
            'side_b_user_ids' => ['required', 'array', 'min:1'],
            'side_b_user_ids.*' => ['integer'],
        ]);

        try {
            $game = $games->createMiniGame(
                $parent,
                $actor,
                $data['title'],
                ($data['has_scheduled_time'] ?? false) ? ($data['starts_at'] ?? null) : null,
                ($data['has_scheduled_time'] ?? false) ? ($data['ends_at'] ?? null) : null,
                $data['side_a_name'] ?? 'Команда A',
                $data['side_b_name'] ?? 'Команда B',
                $data['side_a_user_ids'] ?? [],
                $data['side_b_user_ids'] ?? [],
                (int) $data['side_a_size'],
                (int) $data['side_b_size'],
                GameScoringTypeEnum::from($data['scoring_type'] ?? GameScoringTypeEnum::STREETBALL->value),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('events.games.show', [$parent->routeIdentifier(), $game->id])
            ->with('status', 'Мини-игра создана.');
    }

    public function roster(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameManagementService $games,
    ): RedirectResponse|JsonResponse {
        [$game, $actor] = $this->managedGame($request, $event, $actors, $access, EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_ROSTER);
        $data = $request->validate([
            'side_a_user_ids' => ['required', 'array', 'min:1'],
            'side_a_user_ids.*' => ['integer'],
            'side_b_user_ids' => ['required', 'array', 'min:1'],
            'side_b_user_ids.*' => ['integer'],
            'starters' => ['sometimes', 'required', 'array'],
            'starters.A' => ['required_with:starters', 'array'],
            'starters.A.*' => ['integer'],
            'starters.B' => ['required_with:starters', 'array'],
            'starters.B.*' => ['integer'],
            'captains' => ['sometimes', 'required', 'array'],
            'captains.A' => ['required_with:captains', 'integer'],
            'captains.B' => ['required_with:captains', 'integer'],
        ]);

        try {
            $games->replaceRoster(
                $game,
                $actor,
                $data['side_a_user_ids'] ?? [],
                $data['side_b_user_ids'] ?? [],
                $data['starters'] ?? null,
                $data['captains'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return $request->expectsJson()
                ? response()->json(['message' => $exception->getMessage()], 422)
                : back()->withInput()->with('error', $exception->getMessage());
        }

        $message = isset($data['starters'], $data['captains'])
            ? 'Состав игры, стартовые игроки и капитаны сохранены.'
            : 'Состав игры сохранён.';

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : back()->with('status', $message);
    }

    public function updateMiniGame(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameManagementService $games,
    ): RedirectResponse {
        [$game, $actor] = $this->managedGame($request, $event, $actors, $access, EventResponsibilityPermissionEnum::UPDATE_MINI_GAME);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'has_scheduled_time' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'required_if:has_scheduled_time,1', 'date_format:H:i'],
            'ends_at' => ['nullable', 'required_if:has_scheduled_time,1', 'date_format:H:i'],
            'side_a_name' => ['required', 'string', 'max:80', 'different:side_b_name'],
            'side_b_name' => ['required', 'string', 'max:80', 'different:side_a_name'],
            'side_a_size' => ['required', 'integer', 'min:1', 'max:6'],
            'side_b_size' => ['required', 'integer', 'min:1', 'max:5'],
            'scoring_type' => ['nullable', Rule::enum(GameScoringTypeEnum::class)],
        ]);

        return $this->perform(
            fn () => $games->updateMiniGame(
                $game,
                $actor,
                $data['title'],
                ($data['has_scheduled_time'] ?? false) ? ($data['starts_at'] ?? null) : null,
                ($data['has_scheduled_time'] ?? false) ? ($data['ends_at'] ?? null) : null,
                $data['side_a_name'],
                $data['side_b_name'],
                (int) $data['side_a_size'],
                (int) $data['side_b_size'],
                GameScoringTypeEnum::from($data['scoring_type'] ?? $game->scoring_type->value),
            ),
            'Параметры мини-игры обновлены.',
        );
    }

    public function destroyMiniGame(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameManagementService $games,
    ): RedirectResponse {
        [$game, $actor] = $this->managedGame($request, $event, $actors, $access, EventResponsibilityPermissionEnum::DELETE_MINI_GAME);
        $parentIdentifier = $game->event->routeIdentifier();

        try {
            $games->deleteMiniGame($game, $actor);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('events.show', $parentIdentifier)
            ->with('status', 'Мини-игра удалена.');
    }

    public function statistics(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameManagementService $games,
    ): RedirectResponse|JsonResponse {
        [$game, $actor] = $this->managedGame($request, $event, $actors, $access, EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_STATISTICS);
        $data = $this->validatedStatistics($request);

        try {
            $games->saveStatistics($game, $actor, $data, $data['action'] ?? null);
        } catch (InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return back()->withInput()->with('error', $exception->getMessage());
        }

        $message = 'Статистика сохранена и готова к подтверждению.';

        if (! $request->expectsJson()) {
            return back()->with('status', $message);
        }

        return $this->statisticsJson($game, $message);
    }

    public function score(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameManagementService $games,
    ): RedirectResponse|JsonResponse {
        [$game, $actor] = $this->managedGame($request, $event, $actors, $access, EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_SCORE);
        $data = $request->validate([
            'scores' => ['required', 'array'],
            'scores.A' => ['required', 'integer', 'min:0', 'max:999'],
            'scores.B' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        try {
            $games->saveScore($game, $actor, $data['scores']);
        } catch (InvalidArgumentException $exception) {
            return $request->expectsJson()
                ? response()->json(['message' => $exception->getMessage()], 422)
                : back()->withInput()->with('error', $exception->getMessage());
        }

        if (! $request->expectsJson()) {
            return back()->with('status', 'Счёт сохранён.');
        }

        return $this->statisticsJson($game, 'Счёт сохранён.');
    }

    public function cancelMiniGame(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameManagementService $games,
        CancelEventHandler $cancelEvents,
    ): RedirectResponse|JsonResponse {
        [$game, $actor] = $this->managedGame($request, $event, $actors, $access, EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);

        try {
            if ($game->event->type === EventTypeEnum::GAME) {
                $cancelEvents->handle($game->event->routeIdentifier(), $actor, 'Игра отменена организатором.');
            } else {
                $games->cancelMiniGame($game, $actor);
            }
        } catch (InvalidArgumentException $exception) {
            return $request->expectsJson()
                ? response()->json(['message' => $exception->getMessage()], 422)
                : back()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Игра отменена.',
                'redirect_url' => route('events.show', $game->event->routeIdentifier()),
            ]);
        }

        return redirect()->route('events.show', $game->event->routeIdentifier())
            ->with('status', 'Игра отменена.');
    }

    public function completeStatistics(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameManagementService $games,
    ): RedirectResponse|JsonResponse {
        [$game, $actor] = $this->managedGame($request, $event, $actors, $access, EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);
        $access->assertAllows($game->event, $actor, EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_STATISTICS);
        $data = $this->validatedStatistics($request);

        try {
            $games->saveAndCompleteStatistics($game, $actor, $data);
        } catch (InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return back()->withInput()->with('error', $exception->getMessage());
        }

        $message = 'Игра завершена. Статистика подтверждена и учтена в показателях игроков.';

        if (! $request->expectsJson()) {
            return redirect()->route('events.games.show', [$game->event->routeIdentifier(), $game->id])
                ->with('status', $message);
        }

        return $this->statisticsJson($game, $message, [
            'completed' => true,
            'redirect_url' => route('events.games.show', [$game->event->routeIdentifier(), $game->id]),
        ]);
    }

    public function confirmStatistics(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameManagementService $games,
    ): RedirectResponse|JsonResponse {
        [$game, $actor] = $this->managedGame($request, $event, $actors, $access, EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME);

        try {
            $games->confirmStatistics($game, $actor);
        } catch (InvalidArgumentException $exception) {
            return $request->expectsJson()
                ? response()->json(['message' => $exception->getMessage()], 422)
                : back()->withInput()->with('error', $exception->getMessage());
        }

        $message = 'Статистика подтверждена и учтена в объективных показателях игроков.';

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : back()->with('status', $message);
    }

    /** @return array{Event, Actor} */
    private function managedEvent(
        Request $request,
        string $identifier,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        ?EventResponsibilityPermissionEnum $permission = null,
    ): array {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $event = $events->handle($identifier, $actor);
        abort_unless($permission === null
            ? $access->canManage($event, $actor)
            : $access->allows($event, $actor, $permission), 403);

        return [$event, $actor];
    }

    /** @return array{Game, Actor} */
    private function managedGame(
        Request $request,
        string $identifier,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        EventResponsibilityPermissionEnum $permission,
    ): array {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $gameId = $request->route('game');
        abort_if($gameId === null, 404);
        $parent = Event::query()->whereRouteIdentifier($identifier)->firstOrFail();
        $game = Game::query()
            ->whereKey((int) $gameId)
            ->whereBelongsTo($parent)
            ->with('event')
            ->firstOrFail();
        abort_unless($access->allows($game->event, $actor, $permission), 403);

        return [$game, $actor];
    }

    /** @return array<string, mixed> */
    private function validatedStatistics(Request $request): array
    {
        $rules = [
            'scores' => ['required', 'array'],
            'scores.A' => ['required', 'integer', 'min:0', 'max:999'],
            'scores.B' => ['required', 'integer', 'min:0', 'max:999'],
            'players' => ['array'],
            'action' => ['nullable', 'array'],
            'action.type' => ['required_with:action', Rule::enum(GameActionTypeEnum::class)],
            'action.user_id' => ['required_with:action', 'integer'],
            'action.points' => ['nullable', 'integer', 'min:0', 'max:255'],
            'action.payload' => ['nullable', 'array'],
        ];
        foreach (GamePlayerStatistic::COUNTING_FIELDS as $field) {
            $rules['players.*.'.$field] = ['nullable', 'integer', 'min:0', 'max:999'];
        }

        return $request->validate($rules);
    }

    /** @param array<string, mixed> $extra */
    private function statisticsJson(Game $game, string $message, array $extra = []): JsonResponse
    {
        $game->refresh()->load([
            'sides',
            'playerStatistics',
        ]);
        $scores = $game->sides
            ->mapWithKeys(fn ($side): array => [$side->slot => $side->score])
            ->all();
        $playerPoints = $game->playerStatistics
            ->mapWithKeys(fn (GamePlayerStatistic $statistic): array => [
                (string) $statistic->user_id => $statistic->points($game->scoring_type),
            ])
            ->all();
        $calculatedScores = $game->sides
            ->mapWithKeys(fn ($side): array => [
                $side->slot => $game->playerStatistics
                    ->where('game_side_id', $side->id)
                    ->sum(fn (GamePlayerStatistic $statistic): int => $statistic->points($game->scoring_type)),
            ])
            ->all();

        return response()->json([
            'message' => $message,
            'scores' => $scores,
            'calculated_scores' => $calculatedScores,
            'player_points' => $playerPoints,
            ...$extra,
        ]);
    }

    private function perform(callable $callback, string $message): RedirectResponse
    {
        try {
            $callback();
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', $message);
    }
}
