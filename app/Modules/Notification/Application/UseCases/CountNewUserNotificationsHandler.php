<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\UserNotificationCounterStore;

final class CountNewUserNotificationsHandler
{
    public function __construct(
        private readonly UserNotificationCounterStore $counterStore,
    ) {}

    public function handle(User $user): int
    {
        return $this->counterStore->get((int) $user->id);
    }
}
