<?php

namespace App\Modules\Analytics\Application\Services;

use App\Modules\Analytics\Domain\Models\GameLiveViewSession;

final class GameLiveViewSessionPruner
{
    public function prune(int $batchSize, int $maxBatches): int
    {
        $cutoff = now()->subDays(max(1, (int) config('game_live.history_retention_days', 90)));
        $batchSize = max(1, $batchSize);
        $maxBatches = max(1, $maxBatches);
        $deleted = 0;

        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $ids = GameLiveViewSession::query()
                ->where('last_seen_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += GameLiveViewSession::query()
                ->whereKey($ids)
                ->where('last_seen_at', '<', $cutoff)
                ->delete();
        }

        return $deleted;
    }
}
