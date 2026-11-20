<?php

namespace App\Modules\Favorites\Application\Services;

use App\Modules\Favorites\Domain\Models\Favorite;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Application\Services\FavoritePlayIntentService;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

final class FavoritesManager
{
    public const COOKIE_NAME = 'mskba_guest_favorites';

    public function __construct(
        private readonly FavoriteCountService $counts,
        private readonly FavoritePlayIntentService $interests,
    ) {}


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

    /** Guests without a valid favorites cookie have no rows: skip the DB entirely. */
    public function activeGuestCount(Request $request): int
    {
        if ($request->user() !== null || $this->guestHash($request) === null) {
            return 0;
        }

        return $this->owned($request)->whereNull('trashed_at')->count();
    }

    public function status(Request $request, string $type, int $id): string
    {
        $favorite = $this->owned($request)->where('entity_type', $type)->where('entity_id', $id)->first();

        return $favorite === null ? 'none' : ($favorite->trashed_at === null ? 'active' : 'trashed');
    }

    /**
     * Explicit add/remove actions are idempotent. Legacy authenticated toggle
     * remains supported for the account trash/restore controls.
     */
    public function toggle(Request $request, string $type, int $id, ?string $action = null): Favorite
    {
        $user = $this->user($request);
        abort_if($user?->isBlocked(), 403);
        $hash = $this->guestHash($request);
        // No new guest favorites or restores. Existing anonymous active rows
        // may still be removed, and the first-login claim remains unchanged.
        if ($user === null) {
            abort_unless($hash !== null && $action !== 'add', 401);
            $action = 'remove';
        }

        $favorite = DB::transaction(function () use ($type, $id, $user, $hash, $action): Favorite {
            // Serialize first additions for the canonical account; a lock
            // on a non-existing favorite alone cannot prevent duplicate inserts.
            if ($user !== null) {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            }
            $query = $user !== null
                ? Favorite::query()->where('user_id', $user->id)
                : Favorite::query()->where('guest_token_hash', $hash)->whereNull('user_id');
            $existing = $query->where('entity_type', $type)->where('entity_id', $id)
                ->lockForUpdate()->first();

            if ($existing !== null) {
                if ($action === 'add') {
                    if ($existing->trashed_at !== null) {
                        $existing->forceFill(['trashed_at' => null])->save();
                    }
                } elseif ($action === 'remove') {
                    if ($existing->trashed_at === null) {
                        $existing->forceFill(['trashed_at' => now()])->save();
                    }
                } else {
                    $existing->forceFill([
                        'trashed_at' => $existing->trashed_at === null ? now() : null,
                    ])->save();
                }

                return $existing->refresh();
            }

            abort_unless($user !== null, 401);
            abort_if($action === 'remove', 404);

            return Favorite::query()->create([
                'user_id' => $user->id,
                'entity_type' => $type,
                'entity_id' => $id,
                'trashed_at' => null,
            ]);
        });

        if ($user !== null) {
            $this->counts->changed($user);
        }

        return $favorite;
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

        $claimed = DB::transaction(function () use ($user, $hash): bool {
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

            return $guestItems->isNotEmpty();
        });

        if ($claimed) {
            // Claiming guest favorites on login creates the same basic interests
            // that an authenticated click creates. Do not touch inactive items.
            $venueIds = $this->owned($request)->where('entity_type', 'venue')
                ->whereNull('trashed_at')->pluck('entity_id');
            $visible = Venue::query()->whereIn('id', $venueIds)->get()
                ->filter(fn (Venue $venue): bool => $venue->status->isPubliclyVisible());
            foreach ($visible as $venue) {
                $this->interests->ensure($user, (int) $venue->id);
            }
            $this->counts->changed($user);
        }

        Cookie::queue(Cookie::forget(self::COOKIE_NAME));
    }
}
