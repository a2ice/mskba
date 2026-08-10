<?php

namespace App\Modules\Event\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class GameLiveUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly int $gameId) {}

    public function broadcastOn(): array
    {
        return [new Channel('game.live.'.$this->gameId)];
    }

    public function broadcastAs(): string
    {
        return 'game.live.updated';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return ['game_id' => $this->gameId, 'occurred_at' => now()->toISOString()];
    }
}
