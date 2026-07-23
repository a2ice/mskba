<?php

namespace App\Modules\Telegram\Infrastructure\Services;

use App\Modules\Telegram\Infrastructure\Jobs\ProcessTelegramCallbackJob;
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
            'allowed_updates' => ['callback_query'],
        ], fn (mixed $value): bool => $value !== null), $timeout + 10);
        $processed = 0;

        foreach (data_get($response, 'result', []) as $update) {
            if (! is_array($update) || ! is_numeric(data_get($update, 'update_id'))) {
                continue;
            }

            $callback = data_get($update, 'callback_query');

            if (is_array($callback)) {
                ProcessTelegramCallbackJob::dispatch($callback);
                $processed++;
            }

            Cache::forever(self::OFFSET_CACHE_KEY, ((int) $update['update_id']) + 1);
        }

        return $processed;
    }
}
