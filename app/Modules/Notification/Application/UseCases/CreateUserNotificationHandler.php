<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\Services\UserNotificationCounterStore;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Events\UserNotificationCreated;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Infrastructure\Jobs\SendUserNotificationEmailJob;
use App\Modules\Telegram\Infrastructure\Jobs\SendUserNotificationToTelegramJob;

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

        $selected = $data->payload['delivery_channels'] ?? null;
        $internal = ! is_array($selected) || in_array('in_app', $selected, true);

        $notification = UserNotification::query()->create([
            'user_id' => $targetUserId,
            'type' => $data->type,
            'status' => $internal ? UserNotificationStatusEnum::NEW : UserNotificationStatusEnum::READ,
            'title' => $data->title,
            'body' => $data->body,
            'action_url' => $data->actionUrl,
            'action_text' => $data->actionText,
            'payload' => $data->payload,
        ]);

        if ($internal) {
            $this->counterStore->forget($targetUserId);
            UserNotificationCreated::dispatch($notification->id);
        } else {
            // An external-only subscription should not create a new message
            // or realtime toast in the site's personal messenger.
            if (in_array('email', $selected, true)) {
                SendUserNotificationEmailJob::dispatch($notification->id)->afterCommit();
            }
            if (in_array('telegram', $selected, true)) {
                SendUserNotificationToTelegramJob::dispatch($notification->id)->afterCommit();
            }
        }

        return $notification;
    }
}
