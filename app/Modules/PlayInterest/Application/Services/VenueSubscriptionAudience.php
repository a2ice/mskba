<?php

namespace App\Modules\PlayInterest\Application\Services;

use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\Venue\Domain\Models\Venue;

final class VenueSubscriptionAudience
{
    public function __construct(private readonly RecommendVenuesForIntent $matcher) {}

    /** @return array{total:int,direct_count:int,matched_count:int,direct:array,matched:array} */
    public function forVenue(Venue $venue): array
    {
        $venue->loadMissing(['location.address', 'amenities']);
        $subscriptions = PlayIntent::query()
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->where(function ($query) use ($venue): void {
                $query->where('venue_id', $venue->id)
                    ->orWhereNull('venue_id')
                    ->orWhere('include_similar_venues', true);
            })
            ->with(['user.profile.activeAvatar', 'user.playerProfile.positions'])
            ->get();

        $byUser = [];
        foreach ($subscriptions as $subscription) {
            $user = $subscription->user;
            if ($user === null || $user->isBlocked()) {
                continue;
            }
            $direct = $subscription->venue_id !== null && (int) $subscription->venue_id === (int) $venue->id;
            if (! $direct && ! $this->matcher->matchesVenue($subscription, $venue)) {
                continue;
            }
            $id = (int) $user->canonical()->id;
            $byUser[$id] ??= ['direct' => false, 'public' => true, 'user' => $user];
            $byUser[$id]['direct'] = $byUser[$id]['direct'] || $direct;
            // Privacy wins if the same user has multiple matching subscriptions.
            $byUser[$id]['public'] = $byUser[$id]['public'] && (bool) $subscription->show_on_venue;
        }

        $directUsers = [];
        $matchedUsers = [];
        foreach ($byUser as $row) {
            $user = $row['user'];
            $public = $row['public'];
            $player = $public ? $user->playerProfile : null;
            $positions = $player?->positions?->map(fn ($position) => $position->position?->label())
                ->filter()->values()->all() ?? [];
            $experience = $player?->experience_started_year
                ? max(0, now()->year - (int) $player->experience_started_year) : null;
            $data = [
                // Username remains public in a venue audience; opt-out hides
                // the avatar and game characteristics, not the account login.
                'name' => $public
                    ? ($user->username ?: (trim(($user->profile?->first_name ?? '').' '.($user->profile?->last_name ?? '')) ?: 'Игрок'))
                    : ($user->username ?: 'Игрок'),
                'avatar' => $public ? $user->profile?->avatarUrl() : null,
                'positions' => $positions,
                'experience_years' => $public ? $experience : null,
                'public' => $public,
            ];
            if ($row['direct']) {
                $directUsers[] = $data;
            } else {
                $matchedUsers[] = $data;
            }
        }

        return [
            'total' => count($byUser),
            'direct_count' => count($directUsers),
            'matched_count' => count($matchedUsers),
            'direct' => $directUsers,
            'matched' => $matchedUsers,
        ];
    }
}
