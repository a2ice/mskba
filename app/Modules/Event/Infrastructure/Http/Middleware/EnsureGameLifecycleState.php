<?php

namespace App\Modules\Event\Infrastructure\Http\Middleware;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureGameLifecycleState
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();
        if (! is_string($routeName)) {
            return $next($request);
        }

        $beforeStartOnly = [
            'events.game.roster',
            'events.games.roster',
            'events.game.lineup.update',
            'events.games.lineup.update',
            'events.game.update',
            'events.games.update',
            'events.game.destroy',
            'events.games.destroy',
            'events.game.cancel',
            'events.games.cancel',
        ];
        $liveOnly = [
            'events.game.statistics',
            'events.games.statistics',
            'events.game.score',
            'events.games.score',
        ];
        $afterEndOnly = [
            'events.game.statistics.complete',
            'events.games.statistics.complete',
            'events.game.statistics.confirm',
            'events.games.statistics.confirm',
        ];

        if (! in_array($routeName, [...$beforeStartOnly, ...$liveOnly, ...$afterEndOnly], true)) {
            return $next($request);
        }

        $game = str_starts_with($routeName, 'events.games.')
            ? $this->resolveNestedGame($request)
            : Event::query()->whereRouteIdentifier((string) $request->route('event'))->firstOrFail();

        if (in_array($routeName, $beforeStartOnly, true) && $game->actual_started_at !== null) {
            return $this->reject($request, 'После начала игры состав и параметры изменять нельзя.');
        }

        if (in_array($routeName, $liveOnly, true)) {
            if ($game->actual_started_at === null) {
                return $this->reject($request, 'Сначала необходимо начать игру.');
            }
            if ($game->actual_ended_at !== null) {
                return $this->reject($request, 'Игра уже закончена. Оперативный ввод закрыт.');
            }
        }

        if (in_array($routeName, $afterEndOnly, true) && $game->actual_ended_at === null) {
            return $this->reject($request, 'Сначала необходимо закончить фактическое проведение игры.');
        }

        return $next($request);
    }

    private function resolveNestedGame(Request $request): Game
    {
        $event = Event::query()
            ->whereRouteIdentifier((string) $request->route('event'))
            ->firstOrFail();

        return Game::query()
            ->whereKey((int) $request->route('game'))
            ->whereBelongsTo($event)
            ->firstOrFail();
    }

    private function reject(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->with('error', $message);
    }
}
