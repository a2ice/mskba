<?php

namespace App\Modules\Venue\Infrastructure\Listeners;

use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationDeliveryCategoryEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationSourceEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Venue\Domain\Enums\VenueOwnershipStatusEnum;
use App\Modules\Venue\Domain\Events\VenueOwnershipStatusChanged;
use App\Modules\Venue\Domain\Models\VenueOwnership;

final readonly class CreateVenueOwnershipStatusNotification
{
    public function __construct(private CreateUserNotificationHandler $notifications) {}

    public function handle(VenueOwnershipStatusChanged $event): void
    {
        $ownership = VenueOwnership::query()->with(['venue', 'owner'])->find($event->ownershipId);
        if ($ownership === null) {
            return;
        }

        [$title, $body] = match ($ownership->status) {
            VenueOwnershipStatusEnum::ACTIVE => [
                'Управление площадкой активно',
                "Права управления площадкой «{$ownership->venue->name}» активны.",
            ],
            VenueOwnershipStatusEnum::UNDER_REVIEW => [
                'Управление площадкой уточняется',
                "Права управления площадкой «{$ownership->venue->name}» временно приостановлены. Причина: {$ownership->status_reason}",
            ],
            VenueOwnershipStatusEnum::REVOKED => [
                'Управление площадкой аннулировано',
                "Подтверждённое управление площадкой «{$ownership->venue->name}» аннулировано. Причина: {$ownership->status_reason}",
            ],
        };

        $this->notifications->handle(new CreateUserNotificationDTO(
            userId: (int) $ownership->owner->canonical()->id,
            type: UserNotificationTypeEnum::SYSTEM,
            title: $title,
            body: $body,
            actionUrl: route('venues.management', $ownership->venue, false),
            actionText: 'Открыть площадку',
            payload: [
                'source' => UserNotificationSourceEnum::VENUE_OWNERSHIP_STATUS_CHANGED->value,
                'delivery_category' => UserNotificationDeliveryCategoryEnum::REQUEST->value,
                'ownership_id' => $ownership->public_id,
                'venue_id' => $ownership->venue_id,
                'status' => $ownership->status->value,
            ],
        ));
    }
}
