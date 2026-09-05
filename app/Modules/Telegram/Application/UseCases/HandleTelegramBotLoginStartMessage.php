<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Telegram\Application\Services\TelegramBotLoginChallengeStore;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;

final class HandleTelegramBotLoginStartMessage
{
    public function __construct(
        private readonly TelegramBotLoginChallengeStore $challenges,
        private readonly TelegramBotApiClient $telegram,
    ) {}

    /** @param array<string, mixed> $message */
    public function handle(array $message): void
    {
        $text = data_get($message, 'text');
        $chatId = data_get($message, 'chat.id');
        $chatType = data_get($message, 'chat.type');
        $telegramUserId = data_get($message, 'from.id');

        if (! is_string($text)
            || ! is_numeric($chatId)
            || ! is_numeric($telegramUserId)
            || (string) $chatType !== 'private'
            || (string) $chatId !== (string) $telegramUserId) {
            return;
        }

        $text = trim($text);

        if (preg_match('/^\/start(?:@\w+)?$/', $text) === 1) {
            $this->telegram->call('sendMessage', [
                'chat_id' => (string) $chatId,
                'text' => 'Чтобы войти в MSKBA, вернитесь на сайт и нажмите «Войти через Telegram-бота». Я откроюсь по персональной ссылке и покажу кнопку подтверждения.',
            ]);

            return;
        }

        if (preg_match('/^\/start(?:@\w+)?\s+login_([A-Za-z0-9_-]{43})$/', $text, $matches) !== 1) {
            return;
        }

        $token = $matches[1];

        if ($this->challenges->find($token) === null) {
            $this->telegram->call('sendMessage', [
                'chat_id' => (string) $chatId,
                'text' => 'Ссылка для входа истекла. Вернитесь на сайт MSKBA и запустите вход ещё раз.',
            ]);

            return;
        }

        $this->telegram->call('sendMessage', [
            'chat_id' => (string) $chatId,
            'text' => 'Подтвердите вход в аккаунт MSKBA в открытом браузере.',
            'reply_markup' => [
                'inline_keyboard' => [[
                    [
                        'text' => 'Войти в MSKBA',
                        'callback_data' => "auth:login:{$token}",
                    ],
                ]],
            ],
        ]);
    }
}
