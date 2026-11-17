<?php

namespace App\Modules\Favorites\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Count-only payload; the existing users.{id} channel verifies account ownership. */
final class FavoriteCountUpdatedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly int $userId, public readonly int $count) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('users.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'favorites.count-updated';
    }

    /** @return array{count:int} */
    public function broadcastWith(): array
    {
        return ['count' => $this->count];
    }
}
