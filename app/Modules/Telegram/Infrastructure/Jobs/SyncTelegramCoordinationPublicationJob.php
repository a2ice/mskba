<?php

namespace App\Modules\Telegram\Infrastructure\Jobs;

use App\Modules\Coordination\Domain\Enums\PollResultsVisibilityEnum;
use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Telegram\Application\Services\TelegramCoordinationMessageBuilder;
use App\Modules\Telegram\Domain\Models\TelegramCoordinationPublication;
use App\Modules\Telegram\Infrastructure\Exceptions\TelegramBotApiException;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SyncTelegramCoordinationPublicationJob implements ShouldQueue
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
        TelegramCoordinationMessageBuilder $messages,
    ): void {
        if (! $telegram->isBotConfigured()) {
            return;
        }

        Cache::lock("telegram:coordination-publication:{$this->publicationId}", 30)
            ->block(5, fn () => $this->sync($telegram, $messages));
    }

    private function sync(
        TelegramBotApiClient $telegram,
        TelegramCoordinationMessageBuilder $messages,
    ): void {
        $publication = TelegramCoordinationPublication::query()
            ->with([
                'chat',
                'poll.session.decision.option',
                'poll.options' => fn ($query) => $query
                    ->withCount('selections'),
            ])
            ->find($this->publicationId);

        if ($publication === null || $publication->chat === null || $publication->poll === null) {
            return;
        }

        if (! $publication->chat->is_active || ! $publication->chat->publishes_coordination) {
            try {
                $this->deleteMessage($publication, $telegram);
            } catch (Throwable $exception) {
                $this->recordFailure($publication, $exception);
                throw $exception;
            }

            return;
        }

        $showsVoters = ! $publication->poll->is_anonymous
            && ($publication->poll->results_visibility === PollResultsVisibilityEnum::ALWAYS
                || $publication->poll->status !== PollStatusEnum::OPEN);

        if ($showsVoters) {
            $publication->poll->options->load('selections.ballot.user.profile');
        }

        try {
            $payload = [
                'chat_id' => $publication->chat->telegram_chat_id,
                'text' => $messages->text($publication->poll),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => $messages->replyMarkup($publication->poll),
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
                'status' => $publication->poll->status === PollStatusEnum::OPEN ? 'published' : 'closed',
                'last_error' => null,
                'synced_at' => now(),
            ])->save();
        } catch (TelegramBotApiException $exception) {
            if (str_contains(mb_strtolower($exception->getMessage()), 'message is not modified')) {
                $publication->forceFill([
                    'status' => $publication->poll->status === PollStatusEnum::OPEN ? 'published' : 'closed',
                    'last_error' => null,
                    'synced_at' => now(),
                ])->save();

                return;
            }

            $this->recordFailure($publication, $exception);
            throw $exception;
        } catch (Throwable $exception) {
            $this->recordFailure($publication, $exception);
            throw $exception;
        }
    }

    private function recordFailure(
        TelegramCoordinationPublication $publication,
        Throwable $exception,
    ): void {
        $publication->forceFill([
            'status' => 'failed',
            'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            'synced_at' => now(),
        ])->save();
    }

    private function deleteMessage(
        TelegramCoordinationPublication $publication,
        TelegramBotApiClient $telegram,
    ): void {
        if ($publication->message_id !== null) {
            $telegram->call('deleteMessage', [
                'chat_id' => $publication->chat->telegram_chat_id,
                'message_id' => $publication->message_id,
            ]);
        }

        $publication->forceFill([
            'message_id' => null,
            'status' => 'closed',
            'last_error' => null,
            'synced_at' => now(),
        ])->save();
    }
}
