<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transitional HTTP adapter for canonical nested routes.
 *
 * It validates Event/Game ownership before forwarding to controllers that still
 * accept the published legacy Event identifier. Remove it with legacy routes.
 */
final class NestedGameController extends Controller
{
    public function manage(Request $request, string $event, int $game): Response
    {
        return $this->forward($request, $event, $game, GameControlController::class, '__invoke');
    }

    public function update(Request $request, string $event, int $game): Response
    {
        return $this->forward($request, $event, $game, GameController::class, 'updateMiniGame');
    }

    public function destroy(Request $request, string $event, int $game): Response
    {
        return $this->forward($request, $event, $game, GameController::class, 'destroyMiniGame');
    }

    public function cancel(Request $request, string $event, int $game): Response
    {
        return $this->forward($request, $event, $game, GameController::class, 'cancelMiniGame');
    }

    public function roster(Request $request, string $event, int $game): Response
    {
        return $this->forward($request, $event, $game, GameController::class, 'roster');
    }

    public function score(Request $request, string $event, int $game): Response
    {
        return $this->forward($request, $event, $game, GameController::class, 'score');
    }

    public function statistics(Request $request, string $event, int $game): Response
    {
        return $this->forward($request, $event, $game, GameController::class, 'statistics');
    }

    public function completeStatistics(Request $request, string $event, int $game): Response
    {
        return $this->forward($request, $event, $game, GameController::class, 'completeStatistics');
    }

    public function confirmStatistics(Request $request, string $event, int $game): Response
    {
        return $this->forward($request, $event, $game, GameController::class, 'confirmStatistics');
    }

    public function lifecycle(Request $request, string $event, int $game): Response
    {
        return $this->forward($request, $event, $game, GameLifecycleController::class, 'show');
    }

    public function start(Request $request, string $event, int $game): Response
    {
        return $this->forward($request, $event, $game, GameLifecycleController::class, 'start');
    }

    public function end(Request $request, string $event, int $game): Response
    {
        return $this->forward($request, $event, $game, GameLifecycleController::class, 'end');
    }

    public function lineup(Request $request, string $event, int $game): Response
    {
        return $this->forward($request, $event, $game, GameLineupController::class, '__invoke');
    }

    private function forward(
        Request $request,
        string $eventIdentifier,
        int $gameId,
        string $controller,
        string $method,
    ): Response {
        $event = Event::query()->whereRouteIdentifier($eventIdentifier)->firstOrFail();
        $game = Game::query()
            ->whereKey($gameId)
            ->where('event_id', $event->id)
            ->with('legacyEvent')
            ->firstOrFail();
        abort_if($game->legacyEvent === null, 410, 'Legacy game adapter is unavailable.');

        return app()->call([app($controller), $method], [
            'request' => $request,
            'event' => $game->legacyEvent->routeIdentifier(),
        ]);
    }
}
