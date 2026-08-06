<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\GameLineupService;
use App\Modules\Event\Application\Services\LegacyGameRouteResolver;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class GameLineupController extends Controller
{
    public function __invoke(
        Request $request,
        string $event,
        CurrentActorResolver $actors,
        GameLineupService $lineups,
        LegacyGameRouteResolver $games,
    ): JsonResponse|RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        $data = $request->validate([
            'starters' => ['required', 'array'],
            'starters.A' => ['required', 'array'],
            'starters.A.*' => ['integer'],
            'starters.B' => ['required', 'array'],
            'starters.B.*' => ['integer'],
            'captains' => ['required', 'array'],
            'captains.A' => ['required', 'integer'],
            'captains.B' => ['required', 'integer'],
        ]);

        $game = $games->resolve($event);

        try {
            $lineups->update($game, $actor, $data['starters'], $data['captains']);
        } catch (InvalidArgumentException $exception) {
            return $request->expectsJson()
                ? response()->json(['message' => $exception->getMessage()], 422)
                : back()->withInput()->with('error', $exception->getMessage());
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'Стартовый состав и капитаны сохранены.'])
            : back()->with('status', 'Стартовый состав и капитаны сохранены.');
    }
}
