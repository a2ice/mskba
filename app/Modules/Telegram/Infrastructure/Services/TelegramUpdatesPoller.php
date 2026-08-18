<?php

namespace App\Modules\Telegram\Infrastructure\Services;

use App\Modules\Telegram\Infrastructure\Jobs\ProcessTelegramCallbackJob;
use App\Modules\Telegram\Infrastructure\Jobs\ProcessTelegramMessageJob;
use App\Modules\Telegram\Infrastructure\Jobs\ProcessTelegramReactionCountJob;
use App\Modules\Telegram\Infrastructure\Jobs\ProcessTelegramReactionJob;
use Illuminate\Support\Facades\Cache;

final class TelegramUpdatesPoller
{
    private const OFFSET_CACHE_KEY = 'telegram:updates:offset';

    public function __construct(
        private readonly TelegramBotApiClient $telegram,
    ) {}

    public function pollOnce(): int
    {
        $timeout = max(1, (int) config('telegram.polling_timeout', 25));
        $offset = Cache::get(self::OFFSET_CACHE_KEY);
        $response = $this->telegram->call('getUpdates', array_filter([
            'offset' => is_numeric($offset) ? (int) $offset : null,
            'timeout' => $timeout,
            'allowed_updates' => ['callback_query', 'message', 'message_reaction', 'message_reaction_count'],
        ], fn (mixed $value): bool => $value !== null), $timeout + 10);
        $processed = 0;

        foreach (data_get($response, 'result', []) as $update) {
            if (! is_array($update) || ! is_numeric(data_get($update, 'update_id'))) {
                continue;
            }

            $updateId = (int) $update['update_id'];
            $callback = data_get($update, 'callback_query');

            if (is_array($callback)) {
                ProcessTelegramCallbackJob::dispatch($callback);
                $processed++;
            }

            $message = data_get($update, 'message');

            if (is_array($message)) {
                ProcessTelegramMessageJob::dispatch($message);
                $processed++;
            }

            $reaction = data_get($update, 'message_reaction');

            if (is_array($reaction)) {
                ProcessTelegramReactionJob::dispatch($reaction, $updateId);
                $processed++;
            }

            $reactionCount = data_get($update, 'message_reaction_count');

            if (is_array($reactionCount)) {
                ProcessTelegramReactionCountJob::dispatch($reactionCount, $updateId);
                $processed++;
            }

            Cache::forever(self::OFFSET_CACHE_KEY, $updateId + 1);
        }

        return $processed;
    }
}
