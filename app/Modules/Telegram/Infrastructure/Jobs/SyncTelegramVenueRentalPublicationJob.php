<?php

namespace App\Modules\Telegram\Infrastructure\Jobs;

use App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus;
use App\Modules\Telegram\Application\Services\TelegramVenueRentalMessageBuilder;
use App\Modules\Telegram\Domain\Models\TelegramVenueRentalPublication;
use App\Modules\Telegram\Infrastructure\Exceptions\TelegramBotApiException;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SyncTelegramVenueRentalPublicationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 90];

    public function __construct(public readonly int $publicationId)
    {
        $this->onConnection((string) config('telegram.queue_connection', 'redis'));
        $this->onQueue((string) config('telegram.queues.background', 'telegram-background'));
    }

    public function handle(
        TelegramBotApiClient $telegram,
        TelegramVenueRentalMessageBuilder $messages,
        FeatureFlags $features,
    ): void {
        if (! $features->enabled(VenueRentalFeature::COORDINATION) || ! $telegram->isBotConfigured()) {
            return;
        }

        Cache::lock("telegram:venue-rental-publication:{$this->publicationId}", 30)
            ->block(5, fn () => $this->sync($telegram, $messages));
    }

    private function sync(TelegramBotApiClient $telegram, TelegramVenueRentalMessageBuilder $messages): void
    {
        $publication = TelegramVenueRentalPublication::query()
            ->with(['chat', 'coordination.venue.schedule', 'coordination.booking', 'coordination.participants'])
            ->find($this->publicationId);
        if ($publication === null || $publication->chat === null || $publication->coordination === null) {
            return;
        }

        $coordination = $publication->coordination;
        if (! $publication->chat->is_active
            || ! $publication->chat->publishes_coordination
            || $coordination->visibility !== 'public') {
            $this->delete($publication, $telegram);

            return;
        }

        $publication->venue_booking_id = $coordination->venue_booking_id;
        try {
            $this->sendOrEdit($publication, $telegram, $messages);
        } catch (TelegramBotApiException $exception) {
            if (str_contains(mb_strtolower($exception->getMessage()), 'message is not modified')) {
                $publication->forceFill([
                    'status' => $coordination->status === VenueRentalCoordinationStatus::OPEN ? 'published' : 'closed',
                    'last_error' => null,
                    'synced_at' => now(),
                ])->save();

                return;
            }
            if ($publication->message_id !== null && $this->messageIsMissing($exception)) {
                $publication->message_id = null;
                try {
                    $this->sendOrEdit($publication, $telegram, $messages);
                } catch (Throwable $retryException) {
                    $this->recordFailure($publication, $retryException);
                    throw $retryException;
                }

                return;
            }

            $this->recordFailure($publication, $exception);
            throw $exception;
        } catch (Throwable $exception) {
            $this->recordFailure($publication, $exception);
            throw $exception;
        }
    }

    private function sendOrEdit(
        TelegramVenueRentalPublication $publication,
        TelegramBotApiClient $telegram,
        TelegramVenueRentalMessageBuilder $messages,
    ): void {
        $payload = [
            'chat_id' => $publication->chat->telegram_chat_id,
            'text' => $messages->text($publication->coordination),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $messages->replyMarkup($publication->coordination),
        ];
        if ($publication->message_id === null) {
            $response = $telegram->call('sendMessage', $payload);
            $messageId = data_get($response, 'result.message_id');
            if (! is_int($messageId)) {
                throw new TelegramBotApiException('Telegram did not return message_id.');
            }
            $publication->message_id = $messageId;
            $publication->published_at ??= now();
        } else {
            $telegram->call('editMessageText', [...$payload, 'message_id' => $publication->message_id]);
        }

        $publication->forceFill([
            'status' => $publication->coordination->status === VenueRentalCoordinationStatus::OPEN ? 'published' : 'closed',
            'last_error' => null,
            'synced_at' => now(),
        ])->save();
    }

    private function delete(TelegramVenueRentalPublication $publication, TelegramBotApiClient $telegram): void
    {
        try {
            if ($publication->message_id !== null) {
                $telegram->call('deleteMessage', [
                    'chat_id' => $publication->chat->telegram_chat_id,
                    'message_id' => $publication->message_id,
                ]);
            }
        } catch (TelegramBotApiException $exception) {
            if (! $this->messageIsMissing($exception)) {
                $this->recordFailure($publication, $exception);
                throw $exception;
            }
        }

        $publication->forceFill([
            'message_id' => null,
            'status' => 'closed',
            'last_error' => null,
            'synced_at' => now(),
        ])->save();
    }

    private function messageIsMissing(TelegramBotApiException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'message to edit not found')
            || str_contains($message, 'message to delete not found');
    }

    private function recordFailure(TelegramVenueRentalPublication $publication, Throwable $exception): void
    {
        $publication->forceFill([
            'status' => 'failed',
            'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            'synced_at' => now(),
        ])->save();
    }
}
