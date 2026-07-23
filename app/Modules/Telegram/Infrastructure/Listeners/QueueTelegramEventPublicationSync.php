<?php

namespace App\Modules\Telegram\Infrastructure\Listeners;

use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramEventPublicationJob;

final class QueueTelegramEventPublicationSync
{
    public function handle(EventChanged $changed): void
    {
        SyncTelegramEventPublicationJob::dispatch($changed->eventId)->afterCommit();

        $event = Event::query()->whereKey($changed->eventId)->first(['id', 'starts_at']);

        if ($event?->starts_at->isFuture()) {
            SyncTelegramEventPublicationJob::dispatch($changed->eventId)
                ->delay($event->starts_at)
                ->afterCommit();
        }
    }
}
