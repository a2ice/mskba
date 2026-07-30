<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Telegram\Domain\Models\TelegramChat;
use App\Modules\Telegram\Domain\Models\TelegramEventPublication;
use InvalidArgumentException;

final class PrepareTelegramEventPublicationsHandler
{
    /** @param array<int, int|string> $chatIds */
    public function handle(Event $event, array $chatIds): void
    {
        $chatIds = array_values(array_unique(array_map('intval', $chatIds)));
        $chats = TelegramChat::query()
            ->whereKey($chatIds)
            ->where('is_active', true)
            ->where('publishes_events', true)
            ->get(['id', 'telegram_chat_id']);
        $availableChatIds = $chats->pluck('id')->map(fn ($id): int => (int) $id)->all();

        sort($chatIds);
        sort($availableChatIds);

        if ($chatIds === [] || $chatIds !== $availableChatIds) {
            throw new InvalidArgumentException('Выберите хотя бы один доступный Telegram-чат.');
        }

        foreach ($chats as $chat) {
            TelegramEventPublication::query()->firstOrCreate(
                [
                    'event_id' => $event->id,
                    'chat_id' => (string) $chat->telegram_chat_id,
                ],
                ['status' => 'pending'],
            );
        }
    }
}
