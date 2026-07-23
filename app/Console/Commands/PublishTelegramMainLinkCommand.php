<?php

namespace App\Console\Commands;

use App\Modules\Telegram\Infrastructure\Exceptions\TelegramBotApiException;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:publish-main-link
    {--chat-id= : Telegram chat/channel id or username}
    {--start-param=mskba_chat : Telegram Mini App start parameter}
    {--no-pin : Send message without pinning it}')]
#[Description('Publish and pin Telegram Mini App launch link')]
final class PublishTelegramMainLinkCommand extends Command
{
    public function handle(TelegramBotApiClient $telegram): int
    {
        $botToken = (string) config('telegram.bot_token');
        $botUsername = ltrim((string) config('telegram.bot_username'), '@');
        $chatId = (string) ($this->option('chat-id') ?: config('telegram.main_chat_id'));
        $startParam = (string) $this->option('start-param');

        if ($botToken === '' || $botUsername === '' || $chatId === '') {
            $this->error('Configure TELEGRAM_BOT_TOKEN, TELEGRAM_BOT_USERNAME and TELEGRAM_MAIN_CHAT_ID first.');

            return self::FAILURE;
        }

        $miniAppUrl = sprintf('https://t.me/%s?startapp=%s', $botUsername, rawurlencode($startParam));

        try {
            $message = $telegram->call('sendMessage', [
                'chat_id' => $chatId,
                'text' => implode("\n", [
                    '🏀 Приложение MSKBA',
                    '',
                    'Площадки, игры, тренировки, команды и другие возможности баскетбольного сообщества.',
                ]),
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '🏀 Открыть MSKBA',
                                'url' => $miniAppUrl,
                            ],
                        ],
                    ],
                ],
            ]);
        } catch (TelegramBotApiException $exception) {
            $this->error('Telegram API request failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $messageId = data_get($message, 'result.message_id');

        if (! is_int($messageId)) {
            $this->error('Telegram did not return message_id.');

            return self::FAILURE;
        }

        $this->info("Telegram Mini App link message sent. message_id={$messageId}");

        if (! $this->option('no-pin')) {
            try {
                $telegram->call('pinChatMessage', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'disable_notification' => true,
                ]);
            } catch (TelegramBotApiException $exception) {
                $this->error('Message was sent but pin request failed: '.$exception->getMessage());

                return self::FAILURE;
            }

            $this->info('Message pinned.');
        }

        return self::SUCCESS;
    }
}
