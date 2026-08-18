<?php

namespace App\Console\Commands;

use App\Modules\Telegram\Infrastructure\Exceptions\TelegramBotApiException;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:configure-updates
    {--url= : Override the public webhook URL}
    {--if-configured : Exit successfully when Telegram settings are incomplete}')]
#[Description('Configure Telegram updates transport')]
final class ConfigureTelegramUpdatesCommand extends Command
{
    public function handle(TelegramBotApiClient $telegram): int
    {
        $transport = (string) config('telegram.updates_transport', 'webhook');
        $secret = (string) config('telegram.webhook_secret');

        if (! $telegram->isConfigured() || ($transport === 'webhook' && $secret === '')) {
            $message = 'Configure Telegram bot, chat and updates transport settings first.';

            if ($this->option('if-configured')) {
                $this->warn($message.' Skipped.');

                return self::SUCCESS;
            }

            $this->error($message);

            return self::FAILURE;
        }

        try {
            if ($transport === 'polling') {
                $telegram->call('deleteWebhook', [
                    'drop_pending_updates' => false,
                ]);
                $this->info('Telegram long polling configured; pending updates were preserved.');

                return self::SUCCESS;
            }

            if ($transport !== 'webhook') {
                $this->error("Unsupported Telegram updates transport: {$transport}");

                return self::FAILURE;
            }

            $url = (string) ($this->option('url') ?: route('integrations.telegram.webhook'));
            $telegram->call('setWebhook', [
                'url' => $url,
                'secret_token' => $secret,
                'allowed_updates' => ['callback_query', 'message', 'message_reaction', 'message_reaction_count'],
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
