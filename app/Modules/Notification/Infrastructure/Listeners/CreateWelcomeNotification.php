<?php

namespace App\Modules\Notification\Infrastructure\Listeners;

use App\Modules\Identity\Domain\Events\UserFirstLogin;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationSourceEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CreateWelcomeNotification implements ShouldQueue
{
    use Queueable;

    /* public function withDelay(UserFirstLogin $event): int
    {
        return 10;
    } */

    public function __construct(
        private readonly CreateUserNotificationHandler $createUserNotification,
    ) {}

    public function handle(UserFirstLogin $event): void
    {
        $this->createUserNotification->handle(new CreateUserNotificationDTO(
            userId: $event->userId,
            type: UserNotificationTypeEnum::SYSTEM,
            title: 'Добро пожаловать в MSKBA',
            body: 'Аккаунт создан. Теперь для полноценной работы вам необходимо подтвердить аккаунт',
            actionUrl: route('faq.welcome', [], false),
            actionText: 'Подробнее о первых шагах',
            payload: [
                'source' => UserNotificationSourceEnum::IDENTITY_REGISTRATION->value,
            ],
        ));
    }
}
