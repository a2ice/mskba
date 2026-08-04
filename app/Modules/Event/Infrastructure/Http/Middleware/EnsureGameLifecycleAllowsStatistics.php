<?php

namespace App\Modules\Event\Infrastructure\Http\Middleware;

use App\Modules\Event\Domain\Models\Event;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureGameLifecycleAllowsStatistics
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        if (! in_array($routeName, [
            'events.game.statistics',
            'events.game.score',
            'events.game.statistics.complete',
            'events.game.statistics.confirm',
        ], true)) {
            return $next($request);
        }

        $identifier = (string) $request->route('event');
        $game = Event::query()->whereRouteIdentifier($identifier)->firstOrFail();

        $isLiveWrite = in_array($routeName, [
            'events.game.statistics',
            'events.game.score',
        ], true);

        if ($game->actual_started_at === null) {
            return $this->reject($request, 'Сначала необходимо начать игру.');
        }

        if ($isLiveWrite && $game->actual_ended_at !== null) {
            return $this->reject($request, 'Игра уже закончена. Оперативный ввод статистики закрыт.');
        }

        if (! $isLiveWrite && $game->actual_ended_at === null) {
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
