<?php

namespace App\Modules\PlayInterest\Application\Services;

use App\Modules\Favorites\Domain\Models\Favorite;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\Identity\Domain\Models\Participation\PlayerSelfAssessment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Application\Services\UserPrivacyAccessService;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Models\Venue;

final class VenueSubscriptionAudience
{
    public function __construct(private readonly RecommendVenuesForIntent $matcher) {}

    /** @return array{total:int,direct_count:int,matched_count:int,direct:array,matched:array} */
    public function forVenue(Venue $venue): array
    {
        $viewerId = auth()->user()?->canonical()->id;
        $byUser = $this->collectAudience($venue);
        $directUsers = [];
        $matchedUsers = [];
        foreach ($byUser as $row) {
            $user = $row['user'];
            $isSelf = $viewerId !== null && (int) $viewerId === (int) $user->canonical()->id;
            $public = $row['public'];
            $canViewDetails = $public || $isSelf;
            $player = $canViewDetails ? $user->playerProfile : null;
            $positions = $player?->positions?->map(fn ($position) => $position->position?->label())
                ->filter()->values()->all() ?? [];
            $experience = $player?->experience_started_year
                ? max(0, now()->year - (int) $player->experience_started_year) : null;
            $skills = [];
            if ($player?->selfAssessment) {
                foreach (PlayerSelfAssessment::SKILLS as $attribute => $label) {
                    $score = $player->selfAssessment->{$attribute};
                    if ($score !== null) {
                        $skills[] = ['label' => $label, 'value' => (int) $score];
                    }
                }
            }
            $data = [
                // Username remains public in a venue audience; opt-out hides
                // the avatar and game characteristics, not the account login.
                'name' => $public
                    ? ($user->username ?: (trim(($user->profile?->first_name ?? '').' '.($user->profile?->last_name ?? '')) ?: 'Игрок'))
                    : ($user->username ?: 'Игрок'),
                'avatar' => $canViewDetails ? $user->profile?->avatarUrl() : null,
                'positions' => $positions,
                'experience_years' => $canViewDetails ? $experience : null,
                'height_cm' => $canViewDetails ? $player?->height_cm : null,
                'weight_kg' => $canViewDetails ? $player?->weight_kg : null,
                'body_type' => $canViewDetails ? $player?->body_type?->label() : null,
                'skills' => $canViewDetails ? $skills : [],
                'is_self' => $isSelf,
                'can_view_details' => $canViewDetails,
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
    /**
     * Management-only read model. Never reuse the public page DTO here:
     * even its opt-out rows contain an account username.
     *
     * No offer/message capability is implied by an audience record.
     * @return array{total:int,direct_count:int,matched_count:int,anonymous_count:int,people:array}
     */
    public function forManagement(Venue $venue, User $viewer, VenueAccessResolver $access, UserPrivacyAccessService $privacy): array
    {
        $viewer = $viewer->canonical();
        abort_unless($viewer->isConfirmed() && ! $viewer->isBlocked()
            && ! $venue->trashed()
            && ($viewer->hasSystemRole(\App\Modules\Identity\Domain\Enums\UserSystemRoleEnum::SUPERADMIN)
                || in_array((int) $venue->id, $access->contractViewableVenueIdsFor($viewer), true)), 403);

        $rows = $this->collectAudience($venue);
        $people = [];
        $direct = 0;
        foreach ($rows as $id => $row) {
            $direct += (int) $row['direct'];
            $subject = $row['user']->canonical();
            if (! $row['public'] || ! $subject->isConfirmed() || $subject->isBlocked()
                || ! $privacy->allows($subject, $viewer, UserPrivacySettingTypeEnum::DISCOVERABILITY)
                || ! $privacy->allows($subject, $viewer, UserPrivacySettingTypeEnum::PROFILE)) {
                continue;
            }

            // Only the projection of a separately opted-in matching subscription.
            // Never reveal whether this person also has a private favorite.
            $people[] = [
                'user_id' => $id,
                'name' => $subject->username ?: 'Игрок',
                'avatar' => $privacy->allows($subject, $viewer, UserPrivacySettingTypeEnum::AVATAR)
                    ? $subject->profile?->avatarUrl() : null,
            ];
        }

        return [
            'total' => count($rows),
            'direct_count' => $direct,
            'matched_count' => count($rows) - $direct,
            'anonymous_count' => count($rows) - count($people),
            'people' => $people,
        ];
    }

    /** @return array<int, array{direct:bool,public:bool,user:User}> */
    private function collectAudience(Venue $venue): array
    {
        $venue->loadMissing(['location.address', 'amenities']);
        $subscriptions = PlayIntent::query()
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(function ($query) use ($venue): void {
                $query->where('venue_id', $venue->id)
                    ->orWhereNull('venue_id')
                    ->orWhere('include_similar_venues', true);
            })
            ->with(['user.profile.activeAvatar', 'user.playerProfile.positions', 'user.playerProfile.selfAssessment'])
            ->get();

        $byUser = [];
        foreach ($subscriptions as $subscription) {
            $user = $subscription->user;
            if ($user === null || $user->isBlocked() || $user->canonical()->isBlocked()) {
                continue;
            }
            $direct = $subscription->venue_id !== null && (int) $subscription->venue_id === (int) $venue->id;
            if (! $direct && ! $this->matcher->matchesVenue($subscription, $venue)) {
                continue;
            }
            $id = (int) $user->canonical()->id;
            $byUser[$id] ??= ['direct' => false, 'public' => false, 'user' => $user];
            $byUser[$id]['direct'] = $byUser[$id]['direct'] || $direct;
            // Publicity is consented per subscription, not globally per user.
            // Any *matching active* subscription with opt-in permits displaying
            // the player's profile for this venue; private subscriptions remain
            // counted but must not veto a different explicit public opt-in.
            $byUser[$id]['public'] = $byUser[$id]['public'] || (bool) $subscription->show_on_venue;
        }

        // A favorite is a direct venue interest in its own right. It does not
        // require a PlayIntent, which represents a separate game-preference search.
        // Preserve direct historical subscriptions for backward compatibility,
        // and deduplicate both kinds by the canonical user.
        $favorites = Favorite::query()
            ->where('entity_type', 'venue')
            ->where('entity_id', $venue->id)
            ->whereNull('trashed_at')
            ->whereNotNull('user_id')
            ->with(['user.profile.activeAvatar', 'user.playerProfile.positions', 'user.playerProfile.selfAssessment'])
            ->get();
        foreach ($favorites as $favorite) {
            $user = $favorite->user;
            if ($user === null || $user->isBlocked() || $user->canonical()->isBlocked()) {
                continue;
            }
            $id = (int) $user->canonical()->id;
            $byUser[$id] ??= ['direct' => false, 'public' => false, 'user' => $user];
            $byUser[$id]['direct'] = true;
            // Saving a favorite is not consent to reveal the player's avatar
            // or sporting characteristics to others.
        }


        return $byUser;
    }
}
