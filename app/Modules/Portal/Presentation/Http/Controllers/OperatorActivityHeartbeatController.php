<?php

namespace App\Modules\Portal\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Infrastructure\Broadcasting\OperatorPresenceChangedBroadcast;
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

        $changed = $activity->record($actor, $request->session()->getId(), $data['path']);
        $presence->touch((int) $actor->id);

        // Only a meaningful navigation invalidates the private live view.
        // Recurring one-minute heartbeats never produce broadcast traffic.
        if ($changed) {
            try {
                OperatorPresenceChangedBroadcast::dispatch((int) $actor->id);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }
}
