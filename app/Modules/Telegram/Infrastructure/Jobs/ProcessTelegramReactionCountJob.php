<?php

namespace App\Modules\Telegram\Infrastructure\Jobs;

use App\Modules\Reaction\Application\Services\ReactionAggregateService;
use App\Modules\Reaction\Domain\Enums\ReactionSourceEnum;
use App\Modules\Reaction\Domain\Enums\ReactionSubjectTypeEnum;
use App\Modules\Telegram\Application\Services\TelegramReactionClassifier;
use App\Modules\Telegram\Domain\Models\TelegramContentPublication;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessTelegramReactionCountJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array<string, mixed> $update */
    public function __construct(
        public readonly array $update,
        public readonly ?int $updateId = null,
    ) {
        $this->onConnection((string) config('telegram.queue_connection', 'redis'));
        $this->onQueue((string) config('telegram.queues.background', 'telegram-background'));
    }

    public function handle(
        TelegramReactionClassifier $classifier,
        ReactionAggregateService $aggregates,
    ): void {
        $chatId = data_get($this->update, 'chat.id');
        $messageId = data_get($this->update, 'message_id');
        $occurredAt = data_get($this->update, 'date');
        $counts = data_get($this->update, 'reactions', []);

        if (! is_numeric($chatId) || ! is_numeric($messageId) || ! is_numeric($occurredAt) || ! is_array($counts)) {
            return;
        }

        $publication = TelegramContentPublication::query()
            ->where('message_id', (int) $messageId)
            ->whereHas('chat', fn ($query) => $query->where('telegram_chat_id', (int) $chatId))
            ->first();

        if ($publication === null) {
            return;
        }

        $summary = $classifier->countSummary($counts);
        $aggregates->set(
            ReactionSubjectTypeEnum::CONTENT,
            (int) $publication->content_item_id,
            ReactionSourceEnum::TELEGRAM,
            "content-publication:{$publication->id}",
            $summary['likes'],
            $summary['dislikes'],
            CarbonImmutable::createFromTimestamp((int) $occurredAt, (string) config('app.timezone', 'UTC')),
            $this->updateId,
            [
                'chat_id' => (int) $chatId,
                'message_id' => (int) $messageId,
                'emojis' => $summary['emojis'],
            ],
        );
    }
}
