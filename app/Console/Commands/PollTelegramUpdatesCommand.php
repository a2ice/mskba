<?php

namespace App\Console\Commands;

use App\Modules\Telegram\Infrastructure\Exceptions\TelegramBotApiException;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use App\Modules\Telegram\Infrastructure\Services\TelegramUpdatesPoller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:poll-updates {--once : Perform one long-poll request and exit}')]
#[Description('Receive Telegram callback updates through outgoing long polling')]
final class PollTelegramUpdatesCommand extends Command
{
    public function handle(
        TelegramBotApiClient $telegram,
        TelegramUpdatesPoller $poller,
    ): int {
        if (! $telegram->isConfigured() || config('telegram.updates_transport') !== 'polling') {
            if ($this->option('once')) {
                $this->warn('Telegram polling is not configured.');

                return self::SUCCESS;
            }

            $this->warn('Telegram polling is disabled; the worker will remain idle.');

            while (true) {
                sleep(3600);
            }
        }

        do {
            try {
                $processed = $poller->pollOnce();

                if ($this->option('once')) {
                    $this->info("Telegram updates processed: {$processed}");
                }
            } catch (TelegramBotApiException $exception) {
                report($exception);

                if ($this->option('once')) {
                    $this->error($exception->getMessage());

                    return self::FAILURE;
                }

                sleep(max(1, (int) config('telegram.polling_retry_delay', 5)));
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
