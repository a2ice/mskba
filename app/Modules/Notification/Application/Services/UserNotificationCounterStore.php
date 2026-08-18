<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use Illuminate\Support\Facades\Cache;

final class UserNotificationCounterStore
{
    public function get(int $userId): int
    {
        [$canonicalId, $identityIds] = $this->identityContext($userId);

        return (int) Cache::rememberForever(
            $this->key($canonicalId),
            fn () => $this->countFromDatabase($identityIds),
        );
    }

    public function forget(int $userId): void
    {
        [$canonicalId] = $this->identityContext($userId);
        Cache::forget($this->key($canonicalId));
    }

    public function rebuild(int $userId): int
    {
        [$canonicalId, $identityIds] = $this->identityContext($userId);
        $count = $this->countFromDatabase($identityIds);

        Cache::forever($this->key($canonicalId), $count);

        return $count;
    }

    private function key(int $userId): string
    {
        return "user:{$userId}:notifications:new_count";
    }

    /** @param list<int> $identityIds */
    private function countFromDatabase(array $identityIds): int
    {
        return UserNotification::query()
            ->whereIn('user_id', $identityIds)
            ->where('status', UserNotificationStatusEnum::NEW)
            ->count();
    }

    /** @return array{int, list<int>} */
    private function identityContext(int $userId): array
    {
        $user = User::query()->find($userId);
        if ($user === null) {
            return [$userId, [$userId]];
        }

        $canonical = $user->canonical();

        return [(int) $canonical->id, $canonical->identityIds()];
    }
}
