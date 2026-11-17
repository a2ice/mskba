<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Telegram\Application\Services\TelegramBotLoginChallengeStore;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use Throwable;

final class HandleTelegramBotLinkCallback
{
    public function __construct(
        private readonly TelegramBotLoginChallengeStore $challenges,
        private readonly TelegramBotApiClient $telegram,
    ) {}

    /** @param array<string, mixed> $callback */
    public function handle(array $callback): void
    {
        $callbackId = data_get($callback, 'id');
        $telegram = data_get($callback, 'from');
        $userId = data_get($callback, 'from.id');
        $chatId = data_get($callback, 'message.chat.id');
        $messageId = data_get($callback, 'message.message_id');
        $chatType = data_get($callback, 'message.chat.type');
        $data = data_get($callback, 'data');

        if (! is_string($callbackId) || ! is_array($telegram)
            || ! is_numeric($userId) || ! is_numeric($chatId)
            || ! is_numeric($messageId) || $chatType !== 'private'
            || (string) $userId !== (string) $chatId || ! is_string($data)
            || preg_match('/^auth:link:([A-Za-z0-9_-]{43})$/', $data, $matches) !== 1) {
            return;
        }

        $token = $matches[1];
        $challenge = $this->challenges->find($token);
        if ($challenge === null || ($challenge['mode'] ?? 'login') !== 'link') {
            $this->answer($callbackId, 'Ссылка устарела. Вернитесь в MSKBA и начните заново.');
            return;
        }

        // Acknowledgment must happen quickly so Telegram does not spin.
        $this->answer($callbackId, 'Подтверждаем привязку…');
        $ok = $this->challenges->approveLink($token, $telegram);
        try {
            $this->telegram->call('editMessageText', [
                'chat_id' => (string) $chatId,
                'message_id' => (int) $messageId,
                'text' => $ok
                    ? 'Telegram подтверждён. Вернитесь на сайт MSKBA для завершения привязки.'
                    : 'Подтверждение уже обработано или ссылка истекла.',
                'reply_markup' => ['inline_keyboard' => []],
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function answer(string $id, string $text): void
    {
        try {
            $this->telegram->call('answerCallbackQuery', [
                'callback_query_id' => $id,
                'text' => $text,
                'cache_time' => 0,
            ], 5);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
