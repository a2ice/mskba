<?php

namespace App\Modules\PlayInterest\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\VenueSubscription;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;

final class VenueSubscriptionManager
{
    public function set(User $user, int $venueId, bool $enabled): ?VenueSubscription
    {
        $canonical = $user->canonical();
        abort_if($canonical->isBlocked(), 403);
        if ($enabled) {
            abort_unless(Venue::query()->whereKey($venueId)->where('status', VenueStatusEnum::CONFIRMED)->exists(), 404);
        }

        return DB::transaction(function () use ($canonical, $venueId, $enabled): ?VenueSubscription {
            $subscription = VenueSubscription::query()
                ->where('user_id', $canonical->id)->where('venue_id', $venueId)
                ->lockForUpdate()->first();
            if ($subscription === null && ! $enabled) return null;
            if ($subscription === null) {
                return VenueSubscription::query()->firstOrCreate(
                    ['user_id' => $canonical->id, 'venue_id' => $venueId],
                    ['status' => 'active', 'delivery_channels' => ['in_app']]
                );
            }
            if ($subscription->status !== ($enabled ? 'active' : 'cancelled')) {
                $subscription->forceFill(['status' => $enabled ? 'active' : 'cancelled'])->save();
            }
            return $subscription->refresh();
        });
    }
}
