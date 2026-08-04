<?php

namespace App\Modules\Event\Infrastructure\Http\Middleware;

use App\Modules\Event\Domain\Models\Event;
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
            'events.game.lineup.update',
            'events.game.update',
            'events.game.destroy',
            'events.game.cancel',
        ];
        $liveOnly = [
            'events.game.statistics',
            'events.game.score',
        ];
        $afterEndOnly = [
            'events.game.statistics.complete',
            'events.game.statistics.confirm',
        ];

        if (! in_array($routeName, [...$beforeStartOnly, ...$liveOnly, ...$afterEndOnly], true)) {
            return $next($request);
        }

        $identifier = (string) $request->route('event');
        $game = Event::query()->whereRouteIdentifier($identifier)->firstOrFail();

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

    private function reject(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->with('error', $message);
    }
}
