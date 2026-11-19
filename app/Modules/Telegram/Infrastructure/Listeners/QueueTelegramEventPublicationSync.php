<?php

namespace App\Modules\Telegram\Infrastructure\Listeners;

use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramEventPublicationJob;

final class QueueTelegramEventPublicationSync
{
    public function handle(EventChanged $changed): void
    {
        $aggregateEvent = Event::query()
            ->whereKey($changed->eventId)
            ->first(['id', 'starts_at']);

        // Unscheduled drafts have no meaningful publication or start time.
        // Dispatch only when a date has actually been proposed/published.
        if ($aggregateEvent?->starts_at === null) {
            return;
        }

        SyncTelegramEventPublicationJob::dispatch($changed->eventId, 'change')
            ->afterCommit();

        if ($aggregateEvent?->starts_at->isFuture()) {
            SyncTelegramEventPublicationJob::dispatch(
                $changed->eventId,
                'start:'.$aggregateEvent->starts_at->getTimestamp(),
            )
                ->delay($aggregateEvent->starts_at)
                ->afterCommit();
        }
    }
}
