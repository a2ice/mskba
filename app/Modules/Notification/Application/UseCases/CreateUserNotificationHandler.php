<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;

final class CreateUserNotificationHandler
{
    public function handle(CreateUserNotificationDTO $data): UserNotification
    {
        return UserNotification::query()->create([
            'user_id' => $data->userId,
            'type' => $data->type,
            'status' => UserNotificationStatusEnum::NEW,
            'title' => $data->title,
            'body' => $data->body,
            'action_url' => $data->actionUrl,
            'payload' => $data->payload,
        ]);
    }
}
