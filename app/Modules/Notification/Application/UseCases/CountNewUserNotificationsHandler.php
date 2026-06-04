<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;

final class CountNewUserNotificationsHandler
{
    public function handle(User $user): int
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->where('status', UserNotificationStatusEnum::NEW)
            ->count();
    }
}
