<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;

final class MarkAllUserNotificationsAsReadHandler
{
    public function handle(User $user): int
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->where('status', UserNotificationStatusEnum::NEW)
            ->update([
                'status' => UserNotificationStatusEnum::READ,
                'read_at' => now(),
            ]);
    }
}
