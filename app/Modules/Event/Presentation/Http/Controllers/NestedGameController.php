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
        $this->resolve($event, $game);
        $request->attributes->set('game_management_mode', true);

        return app()->call([app(EventGameController::class), 'show'], [
            'request' => $request,
            'event' => $event,
            'game' => $game,
        ]);
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

    public function endEarly(Request $request, string $event, int $game): Response
    {
        return $this->forward($request, $event, $game, GameLifecycleController::class, 'endEarly');
    }

    public function endPeriod(Request $request, string $event, int $game): Response
    {
        return $this->forward($request, $event, $game, GameLifecycleController::class, 'endPeriod');
    }

    public function startNextPeriod(Request $request, string $event, int $game): Response
    {
        return $this->forward($request, $event, $game, GameLifecycleController::class, 'startNextPeriod');
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
        $this->resolve($eventIdentifier, $gameId);

        return app()->call([app($controller), $method], [
            'request' => $request,
            'event' => $eventIdentifier,
        ]);
    }

    private function resolve(string $eventIdentifier, int $gameId): Game
    {
        $event = Event::query()->whereRouteIdentifier($eventIdentifier)->firstOrFail();

        $game = Game::query()
            ->whereKey($gameId)
            ->whereBelongsTo($event)
            ->firstOrFail();

        abort_if($event->type->value === 'game' && $event->primary_game_id !== $game->id, 404);

        return $game;
    }
}
