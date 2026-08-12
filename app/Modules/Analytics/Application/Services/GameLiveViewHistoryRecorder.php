<?php

namespace App\Modules\Analytics\Application\Services;

use App\Modules\Analytics\Domain\Models\GameLiveViewSession;
use App\Modules\Identity\Domain\Models\UserFingerprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class GameLiveViewHistoryRecorder
{
    public function record(int $gameId, string $viewerId, ?int $userId, ?UserFingerprint $fingerprint): void
    {
        try {
            Cache::lock('game-live-history:'.$gameId.':'.hash('sha256', $viewerId), 5)->block(2, function () use ($gameId, $viewerId, $userId, $fingerprint): void {
                $this->recordLocked($gameId, $viewerId, $userId, $fingerprint);
            });
        } catch (Throwable) {
            // Analytics must never make the public live page unavailable.
        }
    }

    private function recordLocked(int $gameId, string $viewerId, ?int $userId, ?UserFingerprint $fingerprint): void
    {
        DB::transaction(function () use ($gameId, $viewerId, $userId, $fingerprint): void {
            $now = now();
            $viewerKeyHash = hash('sha256', $viewerId);
            $session = GameLiveViewSession::query()
                ->where('game_id', $gameId)
                ->where('viewer_key_hash', $viewerKeyHash)
                ->where('last_seen_at', '>', $now->copy()->subSeconds($this->sessionGapSeconds()))
                ->latest('last_seen_at')
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                GameLiveViewSession::query()->create([
                    'game_id' => $gameId,
                    'user_id' => $userId,
                    'user_fingerprint_id' => $fingerprint?->id,
                    'viewer_key_hash' => $viewerKeyHash,
                    'started_at' => $now,
                    'last_seen_at' => $now,
                ]);

                return;
            }

            $elapsed = max(0, $session->last_seen_at->diffInSeconds($now, false));
            $session->forceFill([
                'user_id' => $userId ?? $session->user_id,
                'user_fingerprint_id' => $fingerprint?->id ?? $session->user_fingerprint_id,
                'last_seen_at' => $now,
                'watched_seconds' => $session->watched_seconds + min($elapsed, $this->sessionGapSeconds()),
            ])->save();
        }, 3);
    }

    private function windowSeconds(): int
    {
        return max(30, (int) config('game_live.presence_window_seconds', 120));
    }

    private function sessionGapSeconds(): int
    {
        return max(
            $this->windowSeconds(),
            (int) config('game_live.history_session_gap_seconds', 180),
        );
    }
}
