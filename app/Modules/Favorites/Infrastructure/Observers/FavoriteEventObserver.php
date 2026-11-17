<?php

namespace App\Modules\Favorites\Infrastructure\Observers;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Favorites\Application\Services\NotifyFavoriteVenueFollowers;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class FavoriteEventObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Event $event): void
    {
        if (!$event->wasRecentlyCreated && !$event->wasChanged([
            'venue_id', 'status', 'visibility', 'starts_at',
        ])) {
            return;
        }

        app(NotifyFavoriteVenueFollowers::class)->forEvent($event);
    }
}
