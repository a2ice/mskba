<?php

namespace App\Modules\Telegram\Infrastructure\Listeners;

use App\Modules\Coordination\Domain\Events\PollChanged;
use App\Modules\Telegram\Domain\Models\TelegramCoordinationPublication;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramCoordinationPublicationJob;

final class QueueTelegramCoordinationPublicationSync
{
    public function handle(PollChanged $changed): void
    {
        TelegramCoordinationPublication::query()
            ->where('poll_id', $changed->pollId)
            ->pluck('id')
            ->each(fn ($publicationId) => SyncTelegramCoordinationPublicationJob::dispatch(
                (int) $publicationId,
            )->afterCommit());

    }
}
