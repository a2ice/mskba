<?php

namespace App\Modules\Coordination\Infrastructure\Listeners;

use App\Modules\Coordination\Domain\Events\VenueRentalCoordinationJoined;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationSourceEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class NotifyOrganizerAboutVenueRentalCoordinationJoin
{
    public function __construct(
        private CreateUserNotificationHandler $notifications,
        private FeatureFlags $features,
    ) {}

    public function handle(VenueRentalCoordinationJoined $event): void
    {
        if (! $this->features->enabled(VenueRentalFeature::COORDINATION)) {
            return;
        }

        $coordination = VenueRentalCoordination::query()->find($event->coordinationId);
        if ($coordination === null || $coordination->organizer_user_id === $event->userId) {
            return;
        }

        $participant = User::query()->find($event->userId);
        $this->notifications->handle(new CreateUserNotificationDTO(
            userId: $coordination->organizer_user_id,
            type: UserNotificationTypeEnum::REMINDER,
            title: 'Новый участник сбора',
            body: ($participant?->username ?? 'Пользователь').' присоединился к сбору «'.$coordination->title.'». Время площадки ещё не забронировано.',
            actionUrl: route('venue-rental-coordinations.show', $coordination, false),
            actionText: 'Открыть сбор',
            payload: [
                'source' => UserNotificationSourceEnum::VENUE_RENTAL_COORDINATION_JOINED->value,
                'coordination_id' => $coordination->public_id,
                'participant_user_id' => $event->userId,
            ],
        ));
    }
}
