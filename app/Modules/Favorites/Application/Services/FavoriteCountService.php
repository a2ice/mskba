<?php

namespace App\Modules\Favorites\Application\Services;

use App\Modules\Favorites\Domain\Models\Favorite;
use App\Modules\Favorites\Infrastructure\Broadcasting\FavoriteCountUpdatedBroadcast;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Only active favorites of the canonical account, never guest or trashed rows. */
final class FavoriteCountService
{
    private function key(User $user): string
    {
        return 'favorites:active-count:v1:'.$user->canonical()->id;
    }

    public function forUser(User $user): int
    {
        $id = $user->canonical()->id;

        return (int) Cache::remember(
            $this->key($user),
            now()->addMinutes(2),
            static fn (): int => Favorite::query()->where('user_id', $id)
                ->whereNull('trashed_at')->count(),
        );
    }

    public function invalidate(User $user): void
    {
        Cache::forget($this->key($user));
    }

    /** Called after a persisted toggle or guest-to-account claim. */
    public function changed(User $user): int
    {
        $this->invalidate($user);
        $count = $this->forUser($user);

        // A temporary Reverb outage must never turn a successful save into a 500.
        try {
            event(new FavoriteCountUpdatedBroadcast((int) $user->canonical()->id, $count));
        } catch (Throwable $error) {
            report($error);
        }

        return $count;
    }
}
