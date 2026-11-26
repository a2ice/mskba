<?php

namespace App\Modules\Dashboard\Application\Services;

use App\Modules\Dashboard\Infrastructure\Broadcasting\DashboardWidgetsInvalidatedBroadcast;
use App\Modules\Identity\Domain\Models\User;

final class DashboardInvalidator
{
    /** @param iterable<int|string|null> $userIds @param list<string> $sources */
    public function send(iterable $userIds, array $sources, bool $catalogChanged = false): void
    {
        $sources = array_values(array_intersect(array_unique($sources), array_keys(DashboardDataRegistry::options())));
        if ($sources === [] && ! $catalogChanged) {
            return;
        }

        $sent = [];
        foreach ($userIds as $id) {
            if (! $id || isset($sent[(int) $id])) {
                continue;
            }
            $user = User::query()->find((int) $id)?->canonical();
            if ($user === null || $user->trashed() || isset($sent[(int) $user->id])) {
                continue;
            }
            $sent[(int) $id] = $sent[(int) $user->id] = true;
            try {
                DashboardWidgetsInvalidatedBroadcast::dispatch($user, $sources, $catalogChanged);
            } catch (\Throwable $exception) {
                // A realtime transport failure must not fail a committed mutation.
                report($exception);
            }
        }
    }
}
