<?php

namespace App\Support\Privacy;

use App\Modules\Analytics\Domain\Models\GameLiveViewSession;
use App\Modules\Identity\Domain\Models\UserFingerprint;

final class TrackingDataHealth
{
    /** @return array<string, int> */
    public function snapshot(): array
    {
        return [
            'fingerprints_total' => UserFingerprint::query()->count(),
            'fingerprints_created_24h' => UserFingerprint::query()->where('created_at', '>=', now()->subDay())->count(),
            'live_sessions_total' => GameLiveViewSession::query()->count(),
            'live_sessions_created_24h' => GameLiveViewSession::query()->where('created_at', '>=', now()->subDay())->count(),
            ...$this->expiredCounts(),
        ];
    }

    /** @return array{fingerprints_expired: int, live_sessions_expired: int} */
    public function expiredCounts(): array
    {
        $fingerprintCutoff = now()->subDays(max(1, (int) config('identity_tracking.fingerprint_retention_days', 90)));
        $liveHistoryCutoff = now()->subDays(max(1, (int) config('game_live.history_retention_days', 90)));

        return [
            'fingerprints_expired' => UserFingerprint::query()->where('last_seen_at', '<', $fingerprintCutoff)->count(),
            'live_sessions_expired' => GameLiveViewSession::query()->where('last_seen_at', '<', $liveHistoryCutoff)->count(),
        ];
    }
}
