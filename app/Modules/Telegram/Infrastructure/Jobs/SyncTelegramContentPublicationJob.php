<?php

namespace App\Modules\Telegram\Infrastructure\Jobs;

use App\Modules\Telegram\Application\Services\TelegramContentMessageBuilder;
use App\Modules\Telegram\Domain\Models\TelegramContentPublication;
use App\Modules\Telegram\Infrastructure\Exceptions\TelegramBotApiException;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SyncTelegramContentPublicationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 90];

    public function __construct(public readonly int $publicationId) {}

    public function handle(
        TelegramBotApiClient $telegram,
        TelegramContentMessageBuilder $messages,
    ): void {
        if (! $telegram->isBotConfigured()) {
            return;
        }

        Cache::lock("telegram:content-publication:{$this->publicationId}", 30)
            ->block(5, fn () => $this->sync($telegram, $messages));
    }

    private function sync(
        TelegramBotApiClient $telegram,
        TelegramContentMessageBuilder $messages,
    ): void {
        $publication = TelegramContentPublication::query()
            ->with(['contentItem', 'chat'])
            ->find($this->publicationId);

        if ($publication === null) {
            return;
        }

        $content = $publication->contentItem;
        $shouldPublish = $content !== null
            && $publication->is_enabled
            && $content->publish_in_telegram
            && $publication->chat?->is_active;

        try {
            if (! $shouldPublish) {
                if ($publication->message_id !== null) {
                    $telegram->call('deleteMessage', [
                        'chat_id' => $publication->chat?->telegram_chat_id,
                        'message_id' => $publication->message_id,
                    ]);
                }

                $publication->forceFill([
                    'message_id' => null,
                    'status' => 'closed',
                    'last_error' => null,
                    'synced_at' => now(),
                ])->save();

                return;
            }

            $payload = [
                'chat_id' => $publication->chat->telegram_chat_id,
                'text' => $messages->text($content),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => false,
                'reply_markup' => $messages->replyMarkup($content),
            ];

            if ($publication->message_id === null) {
                $response = $telegram->call('sendMessage', $payload);
                $messageId = data_get($response, 'result.message_id');

                if (! is_int($messageId)) {
                    throw new TelegramBotApiException('Telegram did not return message_id.');
                }

                $publication->forceFill([
                    'message_id' => $messageId,
                    'published_at' => now(),
                ]);
            } else {
                $telegram->call('editMessageText', [
                    ...$payload,
                    'message_id' => $publication->message_id,
                ]);
            }

            $publication->forceFill([
                'status' => 'published',
                'last_error' => null,
                'synced_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $publication->forceFill([
                'status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                'synced_at' => now(),
            ])->save();

            throw $exception;
        }
    }
}
