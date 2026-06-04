<?php

namespace App\Modules\Notification\Infrastructure\Listeners;

use App\Modules\Identity\Domain\Events\UserRegistered;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationSourceEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;

final class CreateWelcomeNotification
{
    public function __construct(
        private readonly CreateUserNotificationHandler $createUserNotification,
    ) {}

    public function handle(UserRegistered $event): void
    {
        $this->createUserNotification->handle(new CreateUserNotificationDTO(
            userId: $event->userId,
            type: UserNotificationTypeEnum::SYSTEM,
            title: 'Добро пожаловать в MSKBA',
            body: 'Аккаунт создан. Теперь для полноценной работы вам необходимо подтвердить аккаунт',
            actionUrl: route('faq.welcome'),
            actionText: 'Подробнее о первых шагах',
            payload: [
                'source' => UserNotificationSourceEnum::IDENTITY_REGISTRATION->value,
            ],
        ));
    }
}
