<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use Illuminate\Database\Eloquent\Collection;

final class ListNewUserNotificationsHandler
{
    /**
     * @return Collection<int, UserNotification>
     */
    public function handle(User $user, int $limit = 20): Collection
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->where('status', UserNotificationStatusEnum::NEW)
            ->latest()
            ->limit($limit)
            ->get();
    }
}
