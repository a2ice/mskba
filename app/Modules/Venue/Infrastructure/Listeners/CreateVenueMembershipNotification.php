<?php

namespace App\Modules\Venue\Infrastructure\Listeners;

use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationSourceEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Venue\Domain\Events\VenueMembershipGranted;
use App\Modules\Venue\Domain\Events\VenueMembershipRevoked;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class CreateVenueMembershipNotification
{
    public function __construct(
        private CreateUserNotificationHandler $notifications,
        private FeatureFlags $features,
    ) {}

    public function handle(VenueMembershipGranted|VenueMembershipRevoked $event): void
    {
        if (! $this->features->enabled(VenueRentalFeature::RENTAL_FLOW)) {
            return;
        }

        $membership = ContractMembership::query()->find($event->membershipId);
        if ($membership === null) {
            return;
        }

        $court = $membership->scope_type === ContractMembershipScopeTypeEnum::VENUE_COURT
            ? VenueCourt::query()->with('venue')->find($membership->scope_id) : null;
        $venue = $court?->venue ?? ($membership->scope_type === ContractMembershipScopeTypeEnum::VENUE
            ? Venue::query()->find($membership->scope_id) : null);
        $role = VenueMembershipAccessLevelEnum::tryFrom($membership->access_level);
        if ($venue === null || $role === null) {
            return;
        }

        $granted = $event instanceof VenueMembershipGranted;
        $this->notifications->handle(new CreateUserNotificationDTO(
            userId: $membership->user_id,
            type: UserNotificationTypeEnum::SYSTEM,
            title: $court === null
                ? ($granted ? 'Выдана роль площадки' : 'Роль площадки отозвана')
                : ($granted ? 'Выдана роль зала' : 'Роль зала отозвана'),
            body: $granted
                ? "Вам выдана роль «{$role->label()}» на площадке «{$venue->name}»".($court ? " в зале «{$court->name}»." : '.')
                : "Роль «{$role->label()}» на площадке «{$venue->name}»".($court ? " в зале «{$court->name}» отозвана." : ' отозвана.'),
            actionUrl: $court === null
                ? route('venues.show', $venue->routeIdentifier(), false)
                : route('venues.courts.show', [$venue->routeIdentifier(), $court->routeIdentifier()], false),
            payload: [
                'source' => ($granted
                    ? UserNotificationSourceEnum::VENUE_MEMBERSHIP_GRANTED
                    : UserNotificationSourceEnum::VENUE_MEMBERSHIP_REVOKED)->value,
                'membership_id' => $membership->id,
                'venue_id' => $venue->id,
                'venue_court_id' => $court?->id,
            ],
        ));
    }
}
