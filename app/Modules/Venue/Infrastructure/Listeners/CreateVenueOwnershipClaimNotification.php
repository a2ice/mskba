<?php

namespace App\Modules\Venue\Infrastructure\Listeners;

use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationSourceEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimApproved;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimRejected;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimSubmitted;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class CreateVenueOwnershipClaimNotification
{
    public function __construct(
        private CreateUserNotificationHandler $notifications,
        private FeatureFlags $features,
    ) {}

    public function handle(
        VenueOwnershipClaimSubmitted|VenueOwnershipClaimApproved|VenueOwnershipClaimRejected $event,
    ): void {
        if (! $this->features->enabled(VenueRentalFeature::RENTAL_FLOW)) {
            return;
        }

        $claim = VenueOwnershipClaim::query()->with('venue')->find($event->claimId);

        if ($claim === null) {
            return;
        }

        [$source, $title, $body] = match ($event::class) {
            VenueOwnershipClaimSubmitted::class => [
                UserNotificationSourceEnum::VENUE_OWNERSHIP_CLAIM_SUBMITTED,
                'Заявка на владение отправлена',
                "Заявка на владение площадкой «{$claim->venue->name}» принята на рассмотрение.",
            ],
            VenueOwnershipClaimApproved::class => [
                UserNotificationSourceEnum::VENUE_OWNERSHIP_CLAIM_APPROVED,
                'Владение площадкой подтверждено',
                "Вы получили права владельца площадки «{$claim->venue->name}».",
            ],
            VenueOwnershipClaimRejected::class => [
                UserNotificationSourceEnum::VENUE_OWNERSHIP_CLAIM_REJECTED,
                'Заявка на владение отклонена',
                "Заявка на владение площадкой «{$claim->venue->name}» отклонена.",
            ],
        };

        $this->notifications->handle(new CreateUserNotificationDTO(
            userId: $claim->applicant_user_id,
            type: UserNotificationTypeEnum::SYSTEM,
            title: $title,
            body: $body,
            actionUrl: "/account/venue-ownership-claims/{$claim->id}",
            payload: [
                'source' => $source->value,
                'claim_id' => $claim->id,
                'venue_id' => $claim->venue_id,
            ],
        ));
    }
}
