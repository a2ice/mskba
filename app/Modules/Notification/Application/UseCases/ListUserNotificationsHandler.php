<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\UserNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListUserNotificationsHandler
{
    /**
     * @return LengthAwarePaginator<int, UserNotification>
     */
    public function handle(User $user, int $perPage = 20): LengthAwarePaginator
    {
        $identityIds = $user->canonical()->identityIds();

        return UserNotification::query()
            ->whereIn('user_id', $identityIds)
            ->latest()
            ->paginate($perPage);
    }
}
