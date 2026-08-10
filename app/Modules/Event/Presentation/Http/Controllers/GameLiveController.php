<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\GameLiveSnapshotBuilder;
use App\Modules\Event\Application\Services\GamePeriodStatisticsBuilder;
use App\Modules\Event\Application\Services\GameStatisticsFields;
use App\Modules\Event\Application\UseCases\ShowEventHandler;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class GameLiveController extends Controller
{
    public function __invoke(
        Request $request,
        string $event,
        int $game,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        GameStatisticsFields $statisticsFields,
        GamePeriodStatisticsBuilder $periodStatistics,
        GameLiveSnapshotBuilder $snapshots,
    ): Response {
        $actor = $actors->resolveForRequest($request);
        $parent = $events->handle($event, $actor);
        $gameModel = $snapshots->load($parent->id, $game);

        return ThemeResolver::page('events.game-live', [
            'event' => $parent,
            'game' => $gameModel,
            'statisticsFields' => $statisticsFields->all(),
            'periodStatistics' => $periodStatistics->build($gameModel),
        ]);
    }
}
