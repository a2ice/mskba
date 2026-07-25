<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Coordination\Domain\Events\PollChanged;
use App\Modules\Coordination\Domain\Models\Poll;
use App\Modules\Telegram\Domain\Models\TelegramChat;
use App\Modules\Telegram\Domain\Models\TelegramCoordinationPublication;
use InvalidArgumentException;

final class PrepareTelegramCoordinationPublicationsHandler
{
    /** @param array<int, int|string> $chatIds */
    public function handle(Poll $poll, array $chatIds): void
    {
        $chatIds = array_values(array_unique(array_map('intval', $chatIds)));
        $availableChatIds = TelegramChat::query()
            ->whereKey($chatIds)
            ->where('is_active', true)
            ->where('publishes_coordination', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        sort($chatIds);
        sort($availableChatIds);

        if ($chatIds === [] || $chatIds !== $availableChatIds) {
            throw new InvalidArgumentException('Выберите хотя бы один доступный Telegram-чат.');
        }

        foreach ($chatIds as $chatId) {
            TelegramCoordinationPublication::query()->firstOrCreate(
                ['poll_id' => $poll->id, 'chat_id' => $chatId],
                ['status' => 'pending'],
            );
        }

        event(new PollChanged($poll->id));
    }
}
