<?php

namespace App\Modules\Notification\Infrastructure\Listeners;

use App\Modules\Contact\Domain\Events\UserContactConfirmed;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationSourceEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;

final class CreateContactConfirmedNotification
{
    public function __construct(
        private readonly CreateUserNotificationHandler $createUserNotification,
    ) {}

    public function handle(UserContactConfirmed $event): void
    {
        $this->createUserNotification->handle(new CreateUserNotificationDTO(
            userId: $event->userId,
            type: UserNotificationTypeEnum::PROFILE,
            title: 'Контакт подтвержден',
            body: 'Вы подтвердили контакт. Теперь можно продолжить заполнение и подтверждение профиля.',
            actionUrl: route('account', [], false),
            payload: [
                'source' => UserNotificationSourceEnum::CONTACT_CONFIRMATION->value,
                'contact_id' => $event->contactId,
            ],
        ));
    }
}
