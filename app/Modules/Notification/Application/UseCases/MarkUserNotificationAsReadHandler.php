<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\UserNotification;

final class MarkUserNotificationAsReadHandler
{
    public function handle(User $user, UserNotification $notification): UserNotification
    {
        abort_unless((int) $notification->user_id === (int) $user->id, 404);

        $notification->markAsRead();

        return $notification->refresh();
    }
}
