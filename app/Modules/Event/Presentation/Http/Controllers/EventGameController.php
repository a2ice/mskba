<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\GameStatisticsFields;
use App\Modules\Event\Application\UseCases\ShowEventHandler;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class EventGameController extends Controller
{
    public function show(
        Request $request,
        string $event,
        int $game,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        GameStatisticsFields $statisticsFields,
    ): Response {
        $actor = $actors->resolveForRequest($request);
        $parent = $events->handle($event, $actor);
        $gameModel = Game::query()
            ->whereKey($game)
            ->where('event_id', $parent->id)
            ->with([
                'sides.team.memberships.contract',
                'sides.team.memberships.user.profile.activeAvatar',
                'rosterEntries.gameSide',
                'rosterEntries.user.profile.activeAvatar',
                'playerStatistics',
            ])
            ->firstOrFail();

        return ThemeResolver::page('events.game-show', [
            'event' => $parent,
            'game' => $gameModel,
            'canManage' => $actor !== null && $access->canManage($parent, $actor),
            'effectivePermissions' => collect($actor === null ? [] : $access->effectivePermissions($parent, $actor))
                ->map(fn (EventResponsibilityPermissionEnum $permission): string => $permission->value),
            'statisticsFields' => $statisticsFields->all(),
        ]);
    }
}
