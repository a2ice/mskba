<?php

namespace App\Modules\Event\Infrastructure\Listeners;

use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Infrastructure\Broadcasting\GameLiveUpdated;

final class BroadcastChangedGames
{
    public function handle(EventChanged $event): void
    {
        Game::query()
            ->where('event_id', $event->eventId)
            ->pluck('id')
            ->each(fn ($gameId) => GameLiveUpdated::dispatch((int) $gameId));
    }
}
