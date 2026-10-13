<?php

namespace App\Modules\Notification\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** No message body, actor name or private data crosses a revoked subscription. */
final class ConversationChangedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly string $type, public readonly int $id,
        public readonly bool $accessChanged = false) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(($this->type === 'profile' ? 'request-threads.' : 'direct-conversations.').$this->id);
    }

    public function broadcastAs(): string
    {
        return 'conversation.changed';
    }

    public function broadcastWith(): array
    {
        return ['changed' => true, 'access_changed' => $this->accessChanged];
    }
}
