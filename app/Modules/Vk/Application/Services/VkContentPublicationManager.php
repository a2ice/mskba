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

            if (! $enabled || ! VkWallPublisher::configured()) {
                $row?->update(['is_enabled' => false]);

                return;
            }

            $groupId = (int) config('vk.wall.group_id');
            if ($row === null) {
                $row = VkContentPublication::query()->create([
                    'content_item_id' => $content->id, 'is_enabled' => true,
                    'status' => 'pending', 'group_id' => $groupId,
                ]);
            } else {
                $row->update(['is_enabled' => true]);
                // Never redirect existing publications or create another post on edit.
                if ($row->group_id !== $groupId || $row->post_id !== null
                    || in_array($row->status, ['sending', 'unknown'], true)) {
                    return;
                }
                $row->update(['status' => 'pending', 'last_error' => null]);
            }

            PublishVkContentJob::dispatch($row->id)->afterCommit();
        });
    }
}
