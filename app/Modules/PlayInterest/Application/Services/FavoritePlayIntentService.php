<?php

namespace App\Modules\PlayInterest\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;

final class FavoritePlayIntentService
{
    // The heart and "Хочу здесь играть" both use PlayIntent; no second
    // subscription type or duplicate subscription is created.
    public function ensure(User $user, int $venueId): PlayIntent
    {
        $user = $user->canonical();
        abort_if($user->isBlocked(), 403);
        $venue = Venue::query()->findOrFail($venueId);
        abort_unless($venue->status->isPubliclyVisible(), 404);

        return DB::transaction(function () use ($user, $venue, $venueId): PlayIntent {
            $intent = PlayIntent::query()->where('user_id', $user->id)
                ->where('venue_id', $venueId)->whereIn('status', ['active', 'paused'])
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->lockForUpdate()->first();

            if ($intent !== null) {
                // A manually edited or paused subscription is owned by the user;
                // adding a favorite must never silently override its preferences.
                if ($intent->favorite_generated && $intent->status === 'paused') {
                    $intent->forceFill(['status' => 'active'])->save();
                }
                return $intent->refresh();
            }

            return PlayIntent::query()->create([
                'user_id' => $user->id,
                'venue_id' => $venueId,
                'favorite_generated' => true,
                'name' => mb_substr('Подписка на «'.$venue->name.'»', 0, 80),
                'include_similar_venues' => false,
                'cadence' => 'flexible',
                'weekdays' => [], 'dates' => [], 'windows' => [],
                'formats' => [], 'amenity_preferences' => [],
                'access_mode' => 'any', 'venue_kind' => 'any',
                'notify_matches' => true, 'notify_offers' => false,
                'notify_venue_news' => true,
                'game_delivery_channels' => ['in_app'],
                'venue_delivery_channels' => ['in_app'],
                'show_on_venue' => false,
                'status' => 'active',
            ]);
        });
    }

    public function pauseFavoriteGenerated(User $user, int $venueId): void
    {
        // A favorite is a quick subscription; removing it pauses only an
        // untouched quick subscription, never an interest edited in the account.
        $user = $user->canonical();
        PlayIntent::query()->where('user_id', $user->id)
            ->where('venue_id', $venueId)->where('favorite_generated', true)
            ->where('status', 'active')->update(['status' => 'paused']);
    }

    public function enableVenueNews(User $user, int $venueId): PlayIntent
    {
        $intent = $this->ensure($user, $venueId);
        $intent->forceFill(['notify_venue_news' => true, 'favorite_generated' => false])->save();
        return $intent->refresh();
    }

    public function disableVenueNews(User $user, int $venueId): ?PlayIntent
    {
        $user = $user->canonical();
        $intent = PlayIntent::query()->where('user_id', $user->id)
            ->where('venue_id', $venueId)->where('status', 'active')->first();
        if ($intent !== null) {
            $intent->forceFill(['notify_venue_news' => false, 'favorite_generated' => false])->save();
        }
        return $intent;
    }
}
