<?php

namespace App\Modules\Telegram\Infrastructure\Listeners;

use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramEventPublicationJob;

final class QueueTelegramEventPublicationSync
{
    public function handle(EventChanged $changed): void
    {
        $event = Event::query()
            ->whereKey($changed->eventId)
            ->first(['id', 'parent_event_id', 'starts_at']);
        $aggregateEventId = (int) ($event?->parent_event_id ?: $changed->eventId);
        $aggregateEvent = $event?->parent_event_id === null
            ? $event
            : Event::query()->whereKey($aggregateEventId)->first(['id', 'starts_at']);

        SyncTelegramEventPublicationJob::dispatch($aggregateEventId)->afterCommit();

        if ($aggregateEvent?->starts_at->isFuture()) {
            SyncTelegramEventPublicationJob::dispatch($aggregateEventId)
                ->delay($aggregateEvent->starts_at)
                ->afterCommit();
        }
    }
}
