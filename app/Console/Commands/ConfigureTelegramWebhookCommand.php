<?php

namespace App\Console\Commands;

use App\Modules\Telegram\Infrastructure\Exceptions\TelegramBotApiException;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:configure-webhook
    {--url= : Override the public webhook URL}
    {--if-configured : Exit successfully when Telegram settings are incomplete}')]
#[Description('Configure the protected Telegram Bot API webhook')]
final class ConfigureTelegramWebhookCommand extends Command
{
    public function handle(TelegramBotApiClient $telegram): int
    {
        $secret = (string) config('telegram.webhook_secret');

        if (! $telegram->isConfigured() || $secret === '') {
            $message = 'Configure TELEGRAM_BOT_TOKEN, TELEGRAM_MAIN_CHAT_ID and TELEGRAM_WEBHOOK_SECRET first.';

            if ($this->option('if-configured')) {
                $this->warn($message.' Skipped.');

                return self::SUCCESS;
            }

            $this->error($message);

            return self::FAILURE;
        }

        $url = (string) ($this->option('url') ?: route('integrations.telegram.webhook'));

        try {
            $telegram->call('setWebhook', [
                'url' => $url,
                'secret_token' => $secret,
                'allowed_updates' => ['callback_query'],
                'drop_pending_updates' => false,
            ]);
        } catch (TelegramBotApiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Telegram webhook configured: {$url}");

        return self::SUCCESS;
    }
}
