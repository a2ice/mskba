<?php

namespace App\Modules\Favorites\Application\Services;

use App\Modules\Favorites\Domain\Models\Favorite;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FavoritesManager
{
    public const COOKIE_NAME = 'mskba_guest_favorites';

    private function guestHash(Request $request): ?string
    {
        $token = $request->cookie(self::COOKIE_NAME);

        return is_string($token) && preg_match('/\A[a-zA-Z0-9]{64}\z/D', $token) === 1
            ? hash('sha256', $token) : null;
    }

    private function user(Request $request): ?User
    {
        return $request->user()?->canonical();
    }

    /** @return Builder<Favorite> */
    public function owned(Request $request): Builder
    {
        $user = $this->user($request);
        if ($user !== null) {
            return Favorite::query()->where('user_id', $user->id);
        }

        $hash = $this->guestHash($request);

        return $hash !== null
            ? Favorite::query()->where('guest_token_hash', $hash)->whereNull('user_id')
            : Favorite::query()->whereRaw('1=0');
    }

    public function status(Request $request, string $type, int $id): string
    {
        $favorite = $this->owned($request)->where('entity_type', $type)->where('entity_id', $id)->first();

        return $favorite === null ? 'none' : ($favorite->trashed_at === null ? 'active' : 'trashed');
    }

    public function toggle(Request $request, string $type, int $id): Favorite
    {
        $user = $this->user($request);
        abort_if($user?->isBlocked(), 403);

        // A tampered, stale or malformed cookie cannot acquire another guest's list.
        $hash = $this->guestHash($request);
        if ($user === null && $hash === null) {
            $token = Str::random(64);
            $hash = hash('sha256', $token);
            Cookie::queue(cookie(
                self::COOKIE_NAME, $token, 60 * 24 * 90, '/', null,
                $request->isSecure(), true, false, 'lax',
            ));
        }

        return DB::transaction(function () use ($request, $type, $id, $user, $hash): Favorite {
            $query = $user !== null
                ? Favorite::query()->where('user_id', $user->id)
                : Favorite::query()->where('guest_token_hash', $hash)->whereNull('user_id');
            $existing = $query->where('entity_type', $type)->where('entity_id', $id)
                ->lockForUpdate()->first();

            if ($existing !== null) {
                $existing->forceFill([
                    'trashed_at' => $existing->trashed_at === null ? now() : null,
                ])->save();

                return $existing->refresh();
            }

            return Favorite::query()->create([
                'user_id' => $user?->id,
                'guest_token_hash' => $user === null ? $hash : null,
                'entity_type' => $type,
                'entity_id' => $id,
                'trashed_at' => null,
            ]);
        });
    }

    public function setUpcomingEventNotifications(Request $request, int $venueId, bool $enabled): Favorite
    {
        $item = $this->owned($request)
            ->where('entity_type', 'venue')
            ->where('entity_id', $venueId)
            ->whereNull('trashed_at')
            ->firstOrFail();

        abort_if($this->user($request)?->isBlocked(), 403);
        $item->forceFill(['notify_upcoming_events' => $enabled])->save();

        return $item;
    }

    /**
     * Runs on the first authenticated web request carrying the anonymous token.
     * A previously active authenticated favorite always stays active; an active
     * guest item restores an item the account previously moved to its trash.
     * Invalid cookies are not trusted or migrated.
     */
    public function claim(Request $request): void
    {
        $user = $this->user($request);
        $hash = $this->guestHash($request);
        if ($user === null || $hash === null || $user->isBlocked()) {
            return;
        }

        DB::transaction(function () use ($user, $hash): void {
            $guestItems = Favorite::query()->whereNull('user_id')
                ->where('guest_token_hash', $hash)->lockForUpdate()->get();

            foreach ($guestItems as $item) {
                $existing = Favorite::query()->where('user_id', $user->id)
                    ->where('entity_type', $item->entity_type)
                    ->where('entity_id', $item->entity_id)->lockForUpdate()->first();

                if ($existing !== null) {
                    if ($item->trashed_at === null) {
                        $existing->forceFill([
                            'trashed_at' => null,
                            'notify_upcoming_events' => $existing->notify_upcoming_events || $item->notify_upcoming_events,
                        ])->save();
                    }
                    $item->delete();
                } else {
                    $item->forceFill(['user_id' => $user->id, 'guest_token_hash' => null])->save();
                }
            }
        });

        Cookie::queue(Cookie::forget(self::COOKIE_NAME));
    }
}
