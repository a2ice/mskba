<?php

namespace App\Modules\Telegram\Infrastructure\Jobs;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Telegram\Application\Services\TelegramEventMessageBuilder;
use App\Modules\Telegram\Domain\Models\TelegramEventPublication;
use App\Modules\Telegram\Infrastructure\Exceptions\TelegramBotApiException;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SyncTelegramEventPublicationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 90];

    public function __construct(public readonly int $eventId) {}

    public function handle(
        TelegramBotApiClient $telegram,
        TelegramEventMessageBuilder $messages,
    ): void {
        if (! $telegram->isConfigured()) {
            return;
        }

        Cache::lock("telegram:event-publication:{$this->eventId}", 30)
            ->block(5, fn () => $this->sync($telegram, $messages));
    }

    private function sync(
        TelegramBotApiClient $telegram,
        TelegramEventMessageBuilder $messages,
    ): void {
        $event = Event::query()
            ->with(['venue.schedule'])
            ->find($this->eventId);
        $publication = TelegramEventPublication::query()->where('event_id', $this->eventId)->first();

        if ($event === null) {
            return;
        }

        $isPublic = $event->visibility === EventVisibilityEnum::PUBLIC;
        $isVisible = $isPublic && $event->status !== EventStatusEnum::DRAFT;
        $canCreate = $event->status === EventStatusEnum::PUBLISHED
            && $event->starts_at->isFuture()
            && $isPublic;

        if ($publication === null && ! $canCreate) {
            return;
        }

        if ($publication !== null && ! $isVisible) {
            if ($publication->message_id !== null) {
                $telegram->call('deleteMessage', [
                    'chat_id' => $publication->chat_id,
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

        $publication ??= TelegramEventPublication::query()->create([
            'event_id' => $event->id,
            'chat_id' => (string) config('telegram.main_chat_id'),
            'status' => 'pending',
        ]);

        try {
            if ($publication->message_id === null) {
                $response = $telegram->call('sendMessage', [
                    'chat_id' => $publication->chat_id,
                    'text' => $messages->text($event),
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                    'reply_markup' => $messages->replyMarkup($event),
                ]);
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
                    'chat_id' => $publication->chat_id,
                    'message_id' => $publication->message_id,
                    'text' => $messages->text($event),
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                    'reply_markup' => $messages->replyMarkup($event),
                ]);
            }

            $publication->forceFill([
                'status' => $canCreate ? 'published' : 'closed',
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
