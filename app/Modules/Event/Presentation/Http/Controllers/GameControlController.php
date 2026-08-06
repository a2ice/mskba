<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\GameStatisticsFields;
use App\Modules\Event\Application\Services\LegacyGameRouteResolver;
use App\Modules\Event\Application\UseCases\ShowEventHandler;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class GameControlController extends Controller
{
    public function __invoke(
        Request $request,
        string $event,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameStatisticsFields $statisticsFields,
        LegacyGameRouteResolver $games,
    ): Response {
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);

        $game = $games->resolve($event)->load('event');
        abort_unless($access->canManage($game->event, $actor), 403);
        $legacyEvent = $events->handle($event, $actor);

        return ThemeResolver::page('events.game', [
            'event' => $legacyEvent,
            'gameAggregate' => $game,
            'effectivePermissions' => collect($access->effectivePermissions($game->event, $actor))
                ->map(fn (EventResponsibilityPermissionEnum $permission): string => $permission->value),
            'statisticsFields' => $statisticsFields->all(),
        ]);
    }
}
