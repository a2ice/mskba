<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\Services\UserNotificationCounterStore;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Events\UserNotificationCreated;
use App\Modules\Notification\Domain\Models\UserNotification;

final class CreateUserNotificationHandler
{
    public function __construct(
        private readonly UserNotificationCounterStore $counterStore,
    ) {}

    public function handle(CreateUserNotificationDTO $data): UserNotification
    {
        $targetUser = User::query()->find($data->userId);
        $targetUserId = $targetUser === null
            ? $data->userId
            : (int) $targetUser->canonical()->id;

        $notification = UserNotification::query()->create([
            'user_id' => $targetUserId,
            'type' => $data->type,
            'status' => UserNotificationStatusEnum::NEW,
            'title' => $data->title,
            'body' => $data->body,
            'action_url' => $data->actionUrl,
            'action_text' => $data->actionText,
            'payload' => $data->payload,
        ]);

        $this->counterStore->forget($targetUserId);
        UserNotificationCreated::dispatch($notification->id);

        return $notification;
    }
}
