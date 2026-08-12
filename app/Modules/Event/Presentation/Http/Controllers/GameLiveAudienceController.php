<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Application\Services\GameLiveViewHistoryRecorder;
use App\Modules\Event\Application\Services\GameLiveAudiencePresence;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Domain\Models\UserFingerprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GameLiveAudienceController extends Controller
{
    public function __invoke(
        Request $request,
        string $event,
        int $game,
        GameLiveAudiencePresence $presence,
        GameLiveViewHistoryRecorder $history,
    ): JsonResponse {
        $gameModel = Game::query()
            ->whereKey($game)
            ->whereHas('event', fn ($query) => $query->whereRouteIdentifier($event))
            ->firstOrFail();
        if ($gameModel->actual_ended_at !== null || $gameModel->status->isTerminal()) {
            return response()->json([
                'authenticated' => 0,
                'total' => 0,
                'terminal' => true,
            ]);
        }
        $fingerprint = $request->attributes->get('browser_fingerprint');
        $viewerId = $fingerprint instanceof UserFingerprint
            ? $fingerprint->fingerprint_hash
            : hash('sha256', $request->session()->getId());
        $history->record($gameModel->id, $viewerId, $request->user()?->id, $fingerprint instanceof UserFingerprint ? $fingerprint : null);

        return response()->json(
            $presence->touch($gameModel->id, $viewerId, $request->user() !== null)->toArray(),
        );
    }
}
