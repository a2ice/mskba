<?php

namespace App\Modules\Telegram\Infrastructure\Jobs;

use App\Modules\Telegram\Application\UseCases\HandleTelegramBotLoginStartMessage;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessTelegramMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array<string, mixed> $message */
    public function __construct(public readonly array $message) {}

    public function handle(HandleTelegramBotLoginStartMessage $handler): void
    {
        $this->registerPrivateChat();
        $handler->handle($this->message);
        $this->registerPrivateChat();
    }

    private function registerPrivateChat(): void
    {
        if (data_get($this->message, 'chat.type') !== 'private') {
            return;
        }

        $telegramUserId = data_get($this->message, 'from.id');
        $chatId = data_get($this->message, 'chat.id');
        if (! is_numeric($telegramUserId) || ! is_numeric($chatId)) {
            return;
        }

        $account = TelegramAccount::query()
            ->where('telegram_user_id', (int) $telegramUserId)
            ->first();
        if ($account === null) {
            return;
        }

        $account->update([
            'private_chat_id' => (int) $chatId,
            'private_chat_started_at' => $account->private_chat_started_at ?? now(),
            'private_chat_available_at' => now(),
            'private_chat_unavailable_at' => null,
            'last_delivery_error' => null,
        ]);
    }
}
