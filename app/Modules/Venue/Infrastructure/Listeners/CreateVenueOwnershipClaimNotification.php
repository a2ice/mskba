<?php

namespace App\Modules\Venue\Infrastructure\Listeners;

use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationDeliveryCategoryEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationSourceEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimApproved;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimRejected;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimSubmitted;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;

final readonly class CreateVenueOwnershipClaimNotification
{
    public function __construct(
        private CreateUserNotificationHandler $notifications,
    ) {}

    public function handle(
        VenueOwnershipClaimSubmitted|VenueOwnershipClaimApproved|VenueOwnershipClaimRejected $event,
    ): void {
        $claim = VenueOwnershipClaim::query()->with('venue')->find($event->claimId);

        if ($claim === null) {
            return;
        }

        [$source, $title, $body] = match ($event::class) {
            VenueOwnershipClaimSubmitted::class => [
                UserNotificationSourceEnum::VENUE_OWNERSHIP_CLAIM_SUBMITTED,
                'Заявка на управление отправлена',
                "Заявка на подтверждение управления площадкой «{$claim->venue->name}» принята на рассмотрение.",
            ],
            VenueOwnershipClaimApproved::class => [
                UserNotificationSourceEnum::VENUE_OWNERSHIP_CLAIM_APPROVED,
                'Управление площадкой подтверждено',
                "Вы получили права подтверждённого представителя площадки «{$claim->venue->name}».",
            ],
            VenueOwnershipClaimRejected::class => [
                UserNotificationSourceEnum::VENUE_OWNERSHIP_CLAIM_REJECTED,
                'Заявка на управление отклонена',
                "Заявка на подтверждение управления площадкой «{$claim->venue->name}» отклонена.",
            ],
        };

        $this->notifications->handle(new CreateUserNotificationDTO(
            userId: $claim->applicant_user_id,
            type: UserNotificationTypeEnum::SYSTEM,
            title: $title,
            body: $body,
            actionUrl: route('account.venue-ownership.show', $claim, false),
            actionText: 'Открыть заявку',
            payload: [
                'source' => $source->value,
                'delivery_category' => UserNotificationDeliveryCategoryEnum::REQUEST->value,
                'claim_id' => $claim->public_id,
                'venue_id' => $claim->venue_id,
            ],
        ));
    }
}
