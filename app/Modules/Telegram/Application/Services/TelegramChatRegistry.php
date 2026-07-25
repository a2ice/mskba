<?php

namespace App\Modules\Telegram\Application\Services;

use App\Modules\Telegram\Domain\Models\TelegramChat;
use Illuminate\Database\Eloquent\Collection;

final class TelegramChatRegistry
{
    /** @return Collection<int, TelegramChat> */
    public function activeCoordinationChats(): Collection
    {
        $this->registerConfiguredMainChat();

        return TelegramChat::query()
            ->where('is_active', true)
            ->where('publishes_coordination', true)
            ->orderBy('title')
            ->orderBy('id')
            ->get();
    }

    private function registerConfiguredMainChat(): void
    {
        $chatId = config('telegram.main_chat_id');

        if (! is_numeric($chatId)) {
            return;
        }

        TelegramChat::query()->firstOrCreate(
            ['telegram_chat_id' => (int) $chatId],
            [
                'title' => 'Основной чат MSKBA',
                'type' => 'supergroup',
                'is_active' => true,
                'publishes_coordination' => true,
            ],
        );
    }
}
