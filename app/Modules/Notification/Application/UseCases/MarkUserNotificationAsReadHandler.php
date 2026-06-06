<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\UserNotificationCounterStore;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;

final class MarkUserNotificationAsReadHandler
{
    public function __construct(
        private readonly UserNotificationCounterStore $counterStore,
    ) {}

    public function handle(User $user, UserNotification $notification): UserNotification
    {
        abort_unless((int) $notification->user_id === (int) $user->id, 404);

        $updated = UserNotification::query()
            ->whereKey($notification->id)
            ->where('user_id', $user->id)
            ->where('status', UserNotificationStatusEnum::NEW)
            ->update([
                'status' => UserNotificationStatusEnum::READ,
                'read_at' => now(),
            ]);

        if ($updated === 1) {
            $this->counterStore->forget((int) $user->id);
        }

        return $notification->refresh();
    }
}
