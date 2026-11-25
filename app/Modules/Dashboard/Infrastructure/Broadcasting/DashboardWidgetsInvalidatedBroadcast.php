<?php

namespace App\Modules\Dashboard\Infrastructure\Broadcasting;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Private invalidation; never carries data, names, contracts or booking IDs. */
final class DashboardWidgetsInvalidatedBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    private readonly int $userId;

    /** @param list<string> $sources */
    public function __construct(User $user, private readonly array $sources, private readonly bool $catalogChanged = false)
    {
        $this->userId = (int) $user->canonical()->getKey();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('users.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'dashboard.widgets.invalidated';
    }

    public function broadcastWith(): array
    {
        return ['sources' => array_values(array_unique($this->sources)), 'catalog_changed' => $this->catalogChanged];
    }
}
