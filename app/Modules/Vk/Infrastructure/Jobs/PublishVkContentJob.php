<?php

namespace App\Modules\Vk\Infrastructure\Jobs;

use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Vk\Application\Services\VkWallPublisher;
use App\Modules\Vk\Application\Services\VkWallRejected;
use App\Modules\Vk\Domain\Models\VkContentPublication;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PublishVkContentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public int $publicationId) {}

    public function handle(VkWallPublisher $publisher): void
    {
        $publication = DB::transaction(function (): ?VkContentPublication {
            $row = VkContentPublication::query()->lockForUpdate()->find($this->publicationId);
            if ($row === null || ! $row->is_enabled || $row->status !== 'pending' || $row->post_id !== null) {
                return null;
            }
            if (! VkWallPublisher::configured() || $row->group_id !== (int) config('vk.wall.group_id')) {
                $row->update(['status' => 'failed', 'last_error' => 'Интеграция ВКонтакте недоступна или изменена.']);

                return null;
            }
            $row->update(['status' => 'sending', 'last_error' => null]);

            return $row;
        });

        if ($publication === null) {
            return;
        }

        $content = ContentItem::query()->find($publication->content_item_id);
        if ($content === null || $content->status !== ContentStatusEnum::PUBLISHED
            || $content->type === ContentTypeEnum::FAQ || ! $content->publish_in_feed) {
            $publication->update(['status' => 'failed', 'last_error' => 'Материал не опубликован в ленте.']);

            return;
        }

        try {
            $attachment = $publisher->prepareAttachment($content, $publication->group_id);
        } catch (Throwable) {
            $publication->update(['status' => 'failed', 'last_error' => 'Не удалось подготовить обложку ВКонтакте.']);

            return;
        }

        // Re-check selection after photo upload, before the irreversible wall post.
        if (! $publication->fresh()?->is_enabled || ! ContentItem::query()->publishedInFeed()->whereKey($content->id)->exists()) {
            $publication->update(['status' => 'failed', 'last_error' => 'Публикация отменена до отправки.']);

            return;
        }

        try {
            $postId = $publisher->publish($content, $publication->group_id, $attachment);
        } catch (VkWallRejected $exception) {
            // An explicit VK rejection is safe to retry manually.
            $publication->update(['status' => 'failed', 'last_error' => $exception->getMessage()]);

            return;
        } catch (Throwable) {
            // Provider may have accepted the request before the connection failed.
            // Never automatically send another post in this case.
            $publication->update(['status' => 'unknown', 'last_error' => 'Проверьте стену ВКонтакте: результат отправки неизвестен.']);

            return;
        }

        $publication->update(['status' => 'published', 'post_id' => $postId, 'published_at' => now(), 'last_error' => null]);
    }
}
