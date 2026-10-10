<?php

namespace App\Modules\Notification\Infrastructure\Broadcasting;

use App\Modules\Identity\Domain\Models\User;

final class UserNotificationChannel
{
    public function join(User $user, int|string $userId): bool
    {
        // Notification creation and counting are canonical-identity based.
        // An authenticated alias is allowed to join its own canonical channel.
        return (int) $user->id === (int) $userId
            || (int) $user->canonical()->id === (int) $userId;
    }
}
