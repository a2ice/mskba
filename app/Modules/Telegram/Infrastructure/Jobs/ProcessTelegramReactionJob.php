<?php

namespace App\Modules\Telegram\Infrastructure\Jobs;

use App\Modules\Reaction\Application\Services\ReactionService;
use App\Modules\Reaction\Domain\Enums\ReactionSubjectTypeEnum;
use App\Modules\Telegram\Application\Services\TelegramReactionClassifier;
use App\Modules\Telegram\Domain\Models\TelegramContentPublication;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class ProcessTelegramReactionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param array<string, mixed> $reaction
     */
    public function __construct(
        public readonly array $reaction,
        public readonly ?int $updateId = null,
    ) {}

    public function handle(
        TelegramReactionClassifier $classifier,
        ReactionService $reactions,
    ): void {
        $chatId = data_get($this->reaction, 'chat.id');
        $messageId = data_get($this->reaction, 'message_id');
        $telegramUserId = data_get($this->reaction, 'user.id');
        $occurredAt = data_get($this->reaction, 'date');
        $newReactions = data_get($this->reaction, 'new_reaction', []);

        if (
            ! is_numeric($chatId)
            || ! is_numeric($messageId)
            || ! is_numeric($telegramUserId)
            || ! is_numeric($occurredAt)
            || ! is_array($newReactions)
        ) {
            return;
        }

        $publication = TelegramContentPublication::query()
            ->where('message_id', (int) $messageId)
            ->whereHas('chat', fn ($query) => $query->where('telegram_chat_id', (int) $chatId))
            ->first();

        if ($publication === null) {
            return;
        }

        $value = $classifier->classify($newReactions);
        $metadata = [
            'chat_id' => (int) $chatId,
            'message_id' => (int) $messageId,
            'emojis' => $classifier->recognizedEmojis($newReactions),
        ];

        $lockKey = sprintf(
            'telegram:content-reaction:%s:%s:%s',
            (string) $chatId,
            (string) $messageId,
            (string) $telegramUserId,
        );

        Cache::lock($lockKey, 15)->block(3, function () use (
            $reactions,
            $publication,
            $telegramUserId,
            $value,
            $occurredAt,
            $metadata,
        ): void {
            $reactions->setForTelegramUser(
                ReactionSubjectTypeEnum::CONTENT,
                (int) $publication->content_item_id,
                (int) $telegramUserId,
                $value,
                CarbonImmutable::createFromTimestamp(
                    (int) $occurredAt,
                    (string) config('app.timezone', 'UTC'),
                ),
                $this->updateId,
                $metadata,
            );
        });
    }
}
