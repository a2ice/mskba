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

        SyncTelegramEventPublicationJob::dispatch($changed->eventId)->afterCommit();

        if ($aggregateEvent?->starts_at->isFuture()) {
            SyncTelegramEventPublicationJob::dispatch($changed->eventId)
                ->delay($aggregateEvent->starts_at)
                ->afterCommit();
        }
    }
}
