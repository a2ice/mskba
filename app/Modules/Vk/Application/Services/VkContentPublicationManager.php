<?php

namespace App\Modules\Vk\Application\Services;

use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Vk\Domain\Models\VkContentPublication;
use App\Modules\Vk\Infrastructure\Jobs\PublishVkContentJob;
use Illuminate\Support\Facades\DB;

final class VkContentPublicationManager
{
    public function sync(ContentItem $content, bool $selected): void
    {
        $enabled = $selected && $content->status === ContentStatusEnum::PUBLISHED
            && $content->type !== ContentTypeEnum::FAQ && $content->publish_in_feed;

        DB::transaction(function () use ($content, $enabled): void {
            // Serialize changes per material.
            ContentItem::query()->whereKey($content->id)->lockForUpdate()->firstOrFail();
            $row = VkContentPublication::query()->where('content_item_id', $content->id)->lockForUpdate()->first();

            if (! $enabled || ! VkWallPublisher::wallConfigured()) {
                $row?->update(['is_enabled' => false]);

                return;
            }

            $groupId = (int) config('vk.wall.group_id');
            $ready = VkWallPublisher::photoTokenAvailable();
            $status = $ready ? 'pending' : 'awaiting_auth';
            $error = $ready ? null : 'Ожидается повторная авторизация загрузки фото ВКонтакте.';
            if ($row === null) {
                $row = VkContentPublication::query()->create([
                    'content_item_id' => $content->id, 'is_enabled' => true,
                    'status' => $status, 'group_id' => $groupId, 'last_error' => $error,
                ]);
            } else {
                $row->update(['is_enabled' => true]);
                // Never redirect existing publications or create another post on edit.
                if ($row->group_id !== $groupId || $row->post_id !== null
                    || in_array($row->status, ['sending', 'unknown'], true)) {
                    return;
                }
                $row->update(['status' => $status, 'last_error' => $error]);
            }

            if ($ready) {
                PublishVkContentJob::dispatch($row->id)->afterCommit();
            }
        });
    }

    /** Resume only requests that never reached wall.post, for the same selected community. */
    public function resumeAwaitingAuthorization(): int
    {
        if (! VkWallPublisher::configured()) {
            return 0;
        }

        $count = 0;
        $groupId = (int) config('vk.wall.group_id');
        VkContentPublication::query()->where('group_id', $groupId)->where('is_enabled', true)
            ->where('status', 'awaiting_auth')->whereNull('post_id')
            ->chunkById(100, function ($rows) use ($groupId, &$count): void {
                foreach ($rows as $candidate) {
                    DB::transaction(function () use ($candidate, $groupId, &$count): void {
                        // Same lock order as sync(): content first, then publication.
                        $content = ContentItem::query()->whereKey($candidate->content_item_id)->lockForUpdate()->first();
                        $row = VkContentPublication::query()->lockForUpdate()->find($candidate->id);
                        if (! VkWallPublisher::configured() || $row === null || ! $row->is_enabled
                            || (int) $row->group_id !== $groupId || $row->status !== 'awaiting_auth' || $row->post_id !== null
                            || $content === null || $content->status !== ContentStatusEnum::PUBLISHED
                            || $content->type === ContentTypeEnum::FAQ || ! $content->publish_in_feed) {
                            return;
                        }
                        $row->update(['status' => 'pending', 'last_error' => null]);
                        PublishVkContentJob::dispatch($row->id)->afterCommit();
                        $count++;
                    });
                }
            });

        return $count;
    }
}
