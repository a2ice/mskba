<?php

namespace App\Modules\Portal\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Never broadcast a path, a session ID or any visit history. */
final class OperatorPresenceChangedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly int $userId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('operator-presence.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'operator.presence.changed';
    }

    public function broadcastWith(): array
    {
        return ['changed' => true];
    }
}
