<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Telegram\Domain\Models\TelegramContentPublication;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramContentPublicationJob;
use Illuminate\Support\Facades\DB;

final class ContentPublicationManager
{
    /** @param list<int> $chatIds */
    public function syncTelegramChats(ContentItem $content, array $chatIds): void
    {
        $selected = $content->publish_in_telegram
            ? array_values(array_unique(array_map('intval', $chatIds)))
            : [];

        DB::transaction(function () use ($content, $selected): void {
            $existing = TelegramContentPublication::query()
                ->where('content_item_id', $content->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('chat_id');

            foreach ($selected as $chatId) {
                $publication = $existing->get($chatId)
                    ?? new TelegramContentPublication([
                        'content_item_id' => $content->id,
                        'chat_id' => $chatId,
                    ]);

                $publication->forceFill([
                    'is_enabled' => true,
                    'status' => 'pending',
                ])->save();
            }

            foreach ($existing as $chatId => $publication) {
                if (! in_array((int) $chatId, $selected, true)) {
                    $publication->forceFill(['is_enabled' => false])->save();
                }
            }

            $publicationIds = TelegramContentPublication::query()
                ->where('content_item_id', $content->id)
                ->pluck('id')
                ->all();

            DB::afterCommit(function () use ($publicationIds): void {
                foreach ($publicationIds as $publicationId) {
                    SyncTelegramContentPublicationJob::dispatch((int) $publicationId);
                }
            });
        });
    }
}
