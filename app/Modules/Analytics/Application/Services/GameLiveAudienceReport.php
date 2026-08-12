<?php

namespace App\Modules\Analytics\Application\Services;

use App\Modules\Analytics\Domain\Models\GameLiveViewSession;
use Illuminate\Support\Collection;

final class GameLiveAudienceReport
{
    /**
     * @return array{
     *     unique_viewers: int,
     *     authenticated_viewers: int,
     *     guest_viewers: int,
     *     total_watched_seconds: int,
     *     viewers: Collection<int, array{name: string, sessions: int, first_seen_at: mixed, last_seen_at: mixed, watched_seconds: int}>
     * }
     */
    public function build(int $gameId): array
    {
        $sessions = GameLiveViewSession::query()
            ->where('game_id', $gameId)
            ->with('user.profile')
            ->orderByDesc('last_seen_at')
            ->get();
        $authenticated = $sessions->whereNotNull('user_id')->groupBy('user_id');
        $guests = $sessions->whereNull('user_id')->groupBy('viewer_key_hash');

        return [
            'unique_viewers' => $authenticated->count() + $guests->count(),
            'authenticated_viewers' => $authenticated->count(),
            'guest_viewers' => $guests->count(),
            'total_watched_seconds' => (int) $sessions->sum('watched_seconds'),
            'viewers' => $authenticated->map(function (Collection $viewerSessions): array {
                $user = $viewerSessions->first()->user;
                $name = trim(implode(' ', array_filter([
                    $user?->profile?->first_name,
                    $user?->profile?->last_name,
                ])));

                return [
                    'name' => $name ?: $user?->username ?: 'Удалённый пользователь',
                    'sessions' => $viewerSessions->count(),
                    'first_seen_at' => $viewerSessions->min('started_at'),
                    'last_seen_at' => $viewerSessions->max('last_seen_at'),
                    'watched_seconds' => (int) $viewerSessions->sum('watched_seconds'),
                ];
            })->sortByDesc('last_seen_at')->values(),
        ];
    }
}
