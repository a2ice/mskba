<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

#[Signature('telegram:publish-main-link
    {--chat-id= : Telegram chat/channel id or username}
    {--start-param=mskba_chat : Telegram Mini App start parameter}
    {--no-pin : Send message without pinning it}')]
#[Description('Publish and pin Telegram Mini App launch link')]
final class PublishTelegramMainLinkCommand extends Command
{
    public function handle(): int
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
            $message = $this->sendMessage($botToken, $chatId, $miniAppUrl);
        } catch (ConnectionException|RequestException $exception) {
            $this->error('Telegram API request failed: '.$this->safeTelegramError($exception));

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
                $this->pinMessage($botToken, $chatId, $messageId);
            } catch (ConnectionException|RequestException $exception) {
                $this->error('Message was sent but pin request failed: '.$this->safeTelegramError($exception));

                return self::FAILURE;
            }

            $this->info('Message pinned.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function sendMessage(string $botToken, string $chatId, string $miniAppUrl): array
    {
        return Http::asJson()
            ->timeout(30)
            ->connectTimeout(20)
            ->post($this->apiUrl($botToken, 'sendMessage'), [
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
            ])
            ->throw()
            ->json();
    }

    private function pinMessage(string $botToken, string $chatId, int $messageId): void
    {
        Http::asJson()
            ->timeout(30)
            ->connectTimeout(20)
            ->post($this->apiUrl($botToken, 'pinChatMessage'), [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'disable_notification' => true,
            ])
            ->throw();
    }

    private function apiUrl(string $botToken, string $method): string
    {
        return "https://api.telegram.org/bot{$botToken}/{$method}";
    }

    private function safeTelegramError(ConnectionException|RequestException $exception): string
    {
        if ($exception instanceof RequestException && $exception->response !== null) {
            return 'HTTP '.$exception->response->status().' '.$exception->response->body();
        }

        return preg_replace(
            '/bot[^\\s\\/]+/',
            'bot***',
            $exception->getMessage(),
        ) ?? 'connection error';
    }
}
