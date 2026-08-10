<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\GameLiveSnapshotBuilder;
use App\Modules\Event\Application\UseCases\ShowEventHandler;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GameLiveSnapshotController extends Controller
{
    public function __invoke(
        Request $request,
        string $event,
        int $game,
        ShowEventHandler $events,
        CurrentActorResolver $actors,
        GameLiveSnapshotBuilder $snapshots,
    ): JsonResponse {
        $parent = $events->handle($event, $actors->resolveForRequest($request));

        return response()->json($snapshots->build($snapshots->load($parent->id, $game)));
    }
}
