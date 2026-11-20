<?php

namespace App\Modules\PlayInterest\Application\Services;

use App\Modules\Favorites\Domain\Models\Favorite;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\Identity\Domain\Models\Participation\PlayerSelfAssessment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Application\Services\UserPrivacyAccessService;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\PublicUserProfileService;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\EventParticipant;
use App\Modules\Notification\Application\Services\DirectMessageRecipientAccess;
use App\Modules\Notification\Application\Services\DirectMessageBlockService;
use App\Modules\VenueBooking\Application\Services\VenueSpecialTermsManager;
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
    public function canManageAudience(?User $viewer, Venue $venue, bool $backoffice = false): bool
    {
        $viewer = $viewer?->canonical();
        if ($viewer === null || ! $viewer->isConfirmed() || $viewer->isBlocked()
            || $viewer->trashed() || $venue->trashed()) {
            return false;
        }
        if ($backoffice) {
            $permissions = app(BackofficePermissionManager::class);
            return $permissions->allows($viewer, UserOperationalPermissionEnum::BACKOFFICE_ACCESS)
                && $permissions->allows($viewer, UserOperationalPermissionEnum::BACKOFFICE_VENUE_MANAGE);
        }
        $memberships = app(VenueMembershipAccess::class);

        return $memberships->hasActiveVenueManagementRole($viewer, $venue)
            || $memberships->allows($viewer, $venue, VenuePermissionEnum::VIEW_EVENT_INTERESTS);
    }

    /** Explicit management projection: private interest identities never enter the result. */
    public function forManagement(Venue $venue, User $viewer, bool $backoffice = false): array
    {
        $viewer = $viewer->canonical();
        abort_unless($this->canManageAudience($viewer, $venue, $backoffice), 403);
        $privacy = app(UserPrivacyAccessService::class);
        $profiles = app(PublicUserProfileService::class);
        $blocks = app(DirectMessageBlockService::class);
        $messages = app(DirectMessageRecipientAccess::class);
        $offerAccess = app(VenueSpecialTermsManager::class)->canManage($viewer, $venue, $backoffice);
        $canSend = app(VenueMembershipAccess::class)->hasActiveVenueManagementRole($viewer, $venue)
            || app(VenueMembershipAccess::class)->allows($viewer, $venue, VenuePermissionEnum::VIEW_BOOKING_REQUESTS);
        $rows = $this->collectAudience($venue, true);
        $people = [];
        $direct = 0;
        foreach ($rows as $id => $row) {
            $direct += (int) $row['direct'];
            $subject = $row['user']->canonical();
            if (! $row['public'] || ! $subject->isConfirmed() || $subject->isBlocked() || $subject->trashed()
                || ! $privacy->allows($subject, $viewer, UserPrivacySettingTypeEnum::DISCOVERABILITY)
                || ! $privacy->allows($subject, $viewer, UserPrivacySettingTypeEnum::PROFILE)) {
                continue;
            }
            $profile = $profiles->preview($subject, $viewer);
            $roles = collect(UserParticipationRoleEnum::cases())
                ->filter(fn ($role) => $subject->hasActiveRole($role->value)
                    && $privacy->allows($subject, $viewer, UserPrivacySettingTypeEnum::from('role_'.$role->value)))
                ->map(fn ($role) => ['value' => $role->value, 'label' => $role->label()])->values()->all();
            $roleValues = array_column($roles, 'value');
            // Public completed events only. Never expose live schedules or private events.
            $games = in_array('player', $roleValues, true)
                && $privacy->allows($subject, $viewer, UserPrivacySettingTypeEnum::PLAYER_GAMES)
                ? EventParticipant::query()->whereIn('user_id', $subject->identityIds())
                    ->occupiesPlayerSpot()->whereHas('event', fn ($q) => $q
                        ->where('visibility', 'public')->where('status', 'completed')->whereIn('type', ['game', 'game_training']))
                    ->distinct()->count('event_id') : null;
            $organized = in_array('organizer', $roleValues, true)
                ? Event::query()->where('visibility', 'public')->where('status', 'completed')
                    ->whereHas('organizerActor', fn ($q) => $q->whereIn('user_id', $subject->identityIds()))->count() : null;
            $contactAllowed = ! $subject->isSameIdentity($viewer) && ! $blocks->blockedBetween($viewer, $subject);
            $people[] = [
                'user_id' => $id,
                'name' => $profile['name'],
                'url' => $profile['url'],
                'avatar' => $profile['avatar_url'],
                'roles' => $roles, 'games' => $games, 'organized' => $organized,
                'reason' => $row['public_direct'] ? 'Подписка на площадку' : 'Совпадение по условиям',
                'can_message' => $contactAllowed && $canSend && $messages->allows($subject, $viewer),
                'can_offer' => $contactAllowed && $offerAccess && $row['offer_channels'] !== [],
            ];
        }

        return [
            'total' => count($rows), 'direct_count' => $direct,
            'matched_count' => count($rows) - $direct,
            'anonymous_count' => count($rows) - count($people),
            'people' => collect($people)->sortBy([['name', 'asc'], ['user_id', 'asc']])->values()->all(),
        ];
    }

    /** Revalidate subscription consent and current channel availability at send time. */
    public function offerChannels(Venue $venue, User $subject): array
    {
        $rows = $this->collectAudience($venue, true);
        $channels = $rows[$subject->canonical()->id]['offer_channels'] ?? [];
        $available = app(SubscriptionDeliveryChannels::class)->availability($subject);

        return array_values(array_filter($channels, fn ($channel) => $available[$channel]['available'] ?? false));
    }

    /** @return array<int, array{direct:bool,public:bool,user:User}> */
    private function collectAudience(Venue $venue, bool $strict = false): array
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
            if (($strict || ! $direct) && ! $this->matcher->matchesVenue($subscription, $venue)) {
                continue;
            }
            $id = (int) $user->canonical()->id;
            $byUser[$id] ??= ['direct' => false, 'public' => false, 'public_direct' => false, 'offer_channels' => [], 'user' => $user];
            $byUser[$id]['direct'] = $byUser[$id]['direct'] || $direct;
            // Publicity is consented per subscription, not globally per user.
            // Any *matching active* subscription with opt-in permits displaying
            // the player's profile for this venue; private subscriptions remain
            // counted but must not veto a different explicit public opt-in.
            $byUser[$id]['public'] = $byUser[$id]['public'] || (bool) $subscription->show_on_venue;
            if ($subscription->show_on_venue) {
                $byUser[$id]['public_direct'] = $byUser[$id]['public_direct'] || $direct;
                if ($subscription->notify_offers) {
                    $byUser[$id]['offer_channels'] = array_values(array_unique(array_merge(
                        $byUser[$id]['offer_channels'], $subscription->offers_delivery_channels ?? [])));
                }
            }
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
            $byUser[$id] ??= ['direct' => false, 'public' => false, 'public_direct' => false, 'offer_channels' => [], 'user' => $user];
            $byUser[$id]['direct'] = true;
            // Saving a favorite is not consent to reveal the player's avatar
            // or sporting characteristics to others.
        }


        return $byUser;
    }
}
