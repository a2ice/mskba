<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\GameManagementService;
use App\Modules\Event\Application\Services\GameStatisticsFields;
use App\Modules\Event\Application\UseCases\ShowEventHandler;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\GamePlayerStatistic;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\Actor;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

final class GameController extends Controller
{
    public function manage(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameStatisticsFields $statisticsFields,
    ): Response {
        [$game, $actor] = $this->managedEvent($request, $event, $events, $actors, $access);
        abort_unless($game->type === EventTypeEnum::GAME && $game->gameDetail !== null, 404);

        return ThemeResolver::page('events.game', [
            'event' => $this->loadGame($game),
            'statisticsFields' => $statisticsFields->all(),
        ]);
    }

    public function createMiniGame(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameManagementService $games,
    ): RedirectResponse {
        [$parent, $actor] = $this->managedEvent($request, $event, $events, $actors, $access);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'has_scheduled_time' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'required_if:has_scheduled_time,1', 'date_format:H:i'],
            'ends_at' => ['nullable', 'required_if:has_scheduled_time,1', 'date_format:H:i'],
            'side_a_name' => ['nullable', 'string', 'max:80', 'different:side_b_name'],
            'side_b_name' => ['nullable', 'string', 'max:80', 'different:side_a_name'],
            'side_a_size' => ['required', 'integer', 'min:1', 'max:6'],
            'side_b_size' => ['required', 'integer', 'min:1', 'max:5'],
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
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('events.game.manage', $game->routeIdentifier())
            ->with('status', 'Мини-игра создана.');
    }

    public function roster(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameManagementService $games,
    ): RedirectResponse {
        [$game] = $this->managedEvent($request, $event, $events, $actors, $access);
        $data = $request->validate([
            'side_a_user_ids' => ['required', 'array', 'min:1'],
            'side_a_user_ids.*' => ['integer'],
            'side_b_user_ids' => ['required', 'array', 'min:1'],
            'side_b_user_ids.*' => ['integer'],
        ]);

        return $this->perform(
            fn () => $games->replaceRoster($game, $data['side_a_user_ids'] ?? [], $data['side_b_user_ids'] ?? []),
            'Состав игры сохранён.',
        );
    }

    public function updateMiniGame(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameManagementService $games,
    ): RedirectResponse {
        [$game] = $this->managedEvent($request, $event, $events, $actors, $access);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'has_scheduled_time' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'required_if:has_scheduled_time,1', 'date_format:H:i'],
            'ends_at' => ['nullable', 'required_if:has_scheduled_time,1', 'date_format:H:i'],
            'side_a_name' => ['required', 'string', 'max:80', 'different:side_b_name'],
            'side_b_name' => ['required', 'string', 'max:80', 'different:side_a_name'],
            'side_a_size' => ['required', 'integer', 'min:1', 'max:6'],
            'side_b_size' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        return $this->perform(
            fn () => $games->updateMiniGame(
                $game,
                $data['title'],
                ($data['has_scheduled_time'] ?? false) ? ($data['starts_at'] ?? null) : null,
                ($data['has_scheduled_time'] ?? false) ? ($data['ends_at'] ?? null) : null,
                $data['side_a_name'],
                $data['side_b_name'],
                (int) $data['side_a_size'],
                (int) $data['side_b_size'],
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
        [$game] = $this->managedEvent($request, $event, $events, $actors, $access);
        $parentIdentifier = $game->parentEvent?->routeIdentifier();

        try {
            $games->deleteMiniGame($game);
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
        [$game, $actor] = $this->managedEvent($request, $event, $events, $actors, $access);
        $data = $this->validatedStatistics($request);

        try {
            $games->saveStatistics($game, $actor, $data);
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

    public function completeStatistics(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameManagementService $games,
    ): RedirectResponse|JsonResponse {
        [$game, $actor] = $this->managedEvent($request, $event, $events, $actors, $access);
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
            return redirect()->route('events.show', $game->routeIdentifier())
                ->with('status', $message);
        }

        return $this->statisticsJson($game, $message, [
            'completed' => true,
            'redirect_url' => route('events.show', $game->routeIdentifier()),
        ]);
    }

    public function confirmStatistics(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameManagementService $games,
    ): RedirectResponse {
        [$game, $actor] = $this->managedEvent($request, $event, $events, $actors, $access);

        return $this->perform(
            fn () => $games->confirmStatistics($game, $actor),
            'Статистика подтверждена и учтена в объективных показателях игроков.',
        );
    }

    /** @return array{Event, Actor} */
    private function managedEvent(
        Request $request,
        string $identifier,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
    ): array {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $event = $events->handle($identifier, $actor);
        abort_unless($access->canManage($event, $actor), 403);

        return [$event, $actor];
    }

    private function loadGame(Event $game): Event
    {
        return $game->load([
            'parentEvent.participants.user.profile.activeAvatar',
            'gameDetail',
            'gameSides.team.memberships' => fn ($query) => $query
                ->whereHas('contract', fn ($contract) => $contract->where('status', ContractStatusEnum::ACTIVE->value))
                ->with('user.profile.activeAvatar'),
            'gameRosterEntries.user.profile.activeAvatar',
            'gamePlayerStatistics',
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedStatistics(Request $request): array
    {
        $rules = [
            'scores' => ['required', 'array'],
            'scores.A' => ['required', 'integer', 'min:0', 'max:999'],
            'scores.B' => ['required', 'integer', 'min:0', 'max:999'],
            'players' => ['array'],
        ];
        foreach (GamePlayerStatistic::COUNTING_FIELDS as $field) {
            $rules['players.*.'.$field] = ['nullable', 'integer', 'min:0', 'max:999'];
        }

        return $request->validate($rules);
    }

    /** @param array<string, mixed> $extra */
    private function statisticsJson(Event $game, string $message, array $extra = []): JsonResponse
    {
        $game->refresh()->load([
            'gameSides',
            'gamePlayerStatistics',
        ]);
        $scores = $game->gameSides
            ->mapWithKeys(fn ($side): array => [$side->slot => $side->score])
            ->all();
        $playerPoints = $game->gamePlayerStatistics
            ->mapWithKeys(fn (GamePlayerStatistic $statistic): array => [
                (string) $statistic->user_id => $statistic->points(),
            ])
            ->all();
        $calculatedScores = $game->gameSides
            ->mapWithKeys(fn ($side): array => [
                $side->slot => $game->gamePlayerStatistics
                    ->where('game_side_id', $side->id)
                    ->sum(fn (GamePlayerStatistic $statistic): int => $statistic->points()),
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
