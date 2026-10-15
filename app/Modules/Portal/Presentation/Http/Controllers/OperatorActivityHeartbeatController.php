<?php

namespace App\Modules\Portal\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Application\Services\OnlineUserPresence;
use App\Modules\Portal\Application\Services\OperatorActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OperatorActivityHeartbeatController extends Controller
{
    public function __invoke(Request $request, OperatorActivityService $activity,
        OnlineUserPresence $presence): JsonResponse
    {
        $actor = $request->user()->canonical();
        abort_if($actor->isBlocked() || $actor->trashed(), 403);
        $data = $request->validate([
            'path' => ['required', 'string', 'max:250'],
        ]);

        $activity->record($actor, $request->session()->getId(), $data['path']);
        $presence->touch((int) $actor->id);

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }
}
