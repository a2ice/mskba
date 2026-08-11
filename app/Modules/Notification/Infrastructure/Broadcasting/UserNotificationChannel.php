<?php

namespace App\Modules\Notification\Infrastructure\Broadcasting;

use App\Modules\Identity\Domain\Models\User;

final class UserNotificationChannel
{
    public function join(User $user, int|string $userId): bool
    {
        return (int) $user->id === (int) $userId;
    }
}
