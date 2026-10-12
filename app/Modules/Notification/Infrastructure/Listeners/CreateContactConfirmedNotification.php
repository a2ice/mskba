<?php

namespace App\Modules\Notification\Infrastructure\Listeners;

use App\Modules\Contact\Domain\Events\UserContactConfirmed;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
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
        $user = User::query()->find($event->userId)?->canonical();
        if ($user === null) {
            return;
        }

        $needsAccountConfirmation = $user->status === UserStatusEnum::UNCONFIRMED;
        $message = $needsAccountConfirmation
            ? 'Вы подтвердили контакт. Теперь можно продолжить заполнение обязательных данных и подтверждение аккаунта.'
            : 'Вы подтвердили дополнительный контакт. Управлять им и при необходимости выбрать основным можно в разделе «Контакты».';

        $this->createUserNotification->handle(new CreateUserNotificationDTO(
            userId: $event->userId,
            type: UserNotificationTypeEnum::PROFILE,
            title: 'Контакт подтвержден',
            body: $message,
            actionUrl: $needsAccountConfirmation
                ? route('account.confirmation', [], false)
                : route('account.contacts', [], false),
            actionText: $needsAccountConfirmation ? 'Подтвердить аккаунт' : 'Управлять контактами',
            payload: [
                'source' => UserNotificationSourceEnum::CONTACT_CONFIRMATION->value,
                'contact_id' => $event->contactId,
            ],
        ));
    }
}
