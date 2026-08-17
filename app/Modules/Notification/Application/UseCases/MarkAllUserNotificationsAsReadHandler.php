<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\UserNotificationCounterStore;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;

final class MarkAllUserNotificationsAsReadHandler
{
    public function __construct(
        private readonly UserNotificationCounterStore $counterStore,
    ) {}

    public function handle(User $user): int
    {
        $canonical = $user->canonical();
        $updatedCount = UserNotification::query()
            ->whereIn('user_id', $canonical->identityIds())
            ->where('status', UserNotificationStatusEnum::NEW)
            ->update([
                'status' => UserNotificationStatusEnum::READ,
                'read_at' => now(),
            ]);

        if ($updatedCount > 0) {
            $this->counterStore->forget((int) $canonical->id);
        }

        return $updatedCount;
    }
}
