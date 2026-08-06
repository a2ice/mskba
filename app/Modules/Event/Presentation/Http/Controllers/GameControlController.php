<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\LegacyGameRouteResolver;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class GameControlController extends Controller
{
    public function __invoke(
        Request $request,
        string $event,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        LegacyGameRouteResolver $games,
    ): RedirectResponse {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        $game = $games->resolve($event)->load('event');
        abort_unless($access->canManage($game->event, $actor), 403);
        return redirect()->route('events.games.show', [$game->event->routeIdentifier(), $game->id]);
    }
}
