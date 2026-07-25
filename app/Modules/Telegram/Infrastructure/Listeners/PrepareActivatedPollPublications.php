<?php

namespace App\Modules\Telegram\Infrastructure\Listeners;

use App\Modules\Coordination\Domain\Events\PollActivated;
use App\Modules\Coordination\Domain\Events\PollChanged;
use App\Modules\Telegram\Domain\Models\TelegramCoordinationPublication;

final class PrepareActivatedPollPublications
{
    public function handle(PollActivated $activated): void
    {
        $chatIds = TelegramCoordinationPublication::query()
            ->where('poll_id', $activated->previousPollId)
            ->pluck('chat_id');

        foreach ($chatIds as $chatId) {
            TelegramCoordinationPublication::query()->firstOrCreate(
                ['poll_id' => $activated->pollId, 'chat_id' => $chatId],
                ['status' => 'pending'],
            );
        }

        event(new PollChanged($activated->pollId));
    }
}
