<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Models\UserFingerprint;

final class UserFingerprintPruner
{
    public function prune(int $batchSize, int $maxBatches): int
    {
        $cutoff = now()->subDays(max(1, (int) config('identity_tracking.fingerprint_retention_days', 90)));
        $batchSize = max(1, $batchSize);
        $maxBatches = max(1, $maxBatches);
        $deleted = 0;

        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $ids = UserFingerprint::query()
                ->where('last_seen_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += UserFingerprint::query()
                ->whereKey($ids)
                ->where('last_seen_at', '<', $cutoff)
                ->delete();
        }

        return $deleted;
    }
}
