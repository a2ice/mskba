<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use Illuminate\Support\Facades\Cache;

final class UserNotificationCounterStore
{
    public function get(int $userId): int
    {
        return (int) Cache::rememberForever(
            $this->key($userId),
            fn () => $this->countFromDatabase($userId),
        );
    }

    public function forget(int $userId): void
    {
        Cache::forget($this->key($userId));
    }

    public function rebuild(int $userId): int
    {
        $count = $this->countFromDatabase($userId);

        Cache::forever($this->key($userId), $count);

        return $count;
    }

    private function key(int $userId): string
    {
        return "user:{$userId}:notifications:new_count";
    }

    private function countFromDatabase(int $userId): int
    {
        return UserNotification::query()
            ->where('user_id', $userId)
            ->where('status', UserNotificationStatusEnum::NEW)
            ->count();
    }
}
