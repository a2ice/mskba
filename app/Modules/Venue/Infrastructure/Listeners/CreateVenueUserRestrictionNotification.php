<?php

namespace App\Modules\Venue\Infrastructure\Listeners;

use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationDeliveryCategoryEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationSourceEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Venue\Domain\Events\VenueUserRestrictionImposed;
use App\Modules\Venue\Domain\Events\VenueUserRestrictionRevoked;
use App\Modules\Venue\Domain\Models\VenueUserRestriction;

final readonly class CreateVenueUserRestrictionNotification
{
    public function __construct(private CreateUserNotificationHandler $notifications) {}

    public function handle(VenueUserRestrictionImposed|VenueUserRestrictionRevoked $event): void
    {
        $restriction = VenueUserRestriction::query()->with(['venue', 'user'])->find($event->restrictionId);
        if ($restriction === null || $restriction->user === null) {
            return;
        }

        $imposed = $event instanceof VenueUserRestrictionImposed;
        $subject = $restriction->type->label();
        $title = $imposed ? 'Ограничение заявок по площадке' : 'Ограничение заявок снято';
        $body = $imposed
            ? "{$subject} для площадки «{$restriction->venue->name}» заблокированы. Причина: {$restriction->reason}"
            : "Ограничение «{$subject}» для площадки «{$restriction->venue->name}» снято."
                .(filled($restriction->revoke_reason) ? ' Комментарий: '.$restriction->revoke_reason : '');

        $this->notifications->handle(new CreateUserNotificationDTO(
            userId: (int) $restriction->user->canonical()->id,
            type: UserNotificationTypeEnum::SYSTEM,
            title: $title,
            body: $body,
            actionUrl: route('venues.show', $restriction->venue->routeIdentifier(), false),
            actionText: 'Открыть площадку',
            payload: [
                'source' => $imposed
                    ? UserNotificationSourceEnum::VENUE_USER_RESTRICTION_IMPOSED->value
                    : UserNotificationSourceEnum::VENUE_USER_RESTRICTION_REVOKED->value,
                'delivery_category' => UserNotificationDeliveryCategoryEnum::REQUEST->value,
                'restriction_id' => $restriction->public_id,
                'venue_id' => $restriction->venue_id,
                'restriction_type' => $restriction->type->value,
            ],
        ));
    }
}
