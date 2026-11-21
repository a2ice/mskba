<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Vk\Application\Services\VkContentPublicationManager;
use App\Modules\Vk\Application\Services\VkWallPublisher;
use App\Modules\Vk\Domain\Models\VkContentPublication;
use App\Modules\Vk\Infrastructure\Jobs\PublishVkContentJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class VkContentPublicationTest extends TestCase
{
    use RefreshDatabase;

    private function item(): ContentItem
    {
        $user = User::factory()->create();

        return ContentItem::query()->create([
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
            'title' => 'Игра в зале',
            'alias' => 'vk-test-news',
            'short_description' => 'Нашли площадку.',
            'full_description' => 'Полный текст.',
            'content_format' => ContentFormatEnum::MARKDOWN,
            'type' => ContentTypeEnum::MATERIAL,
            'status' => ContentStatusEnum::PUBLISHED,
            'publish_in_feed' => true,
            'publish_in_telegram' => false,
            'feed_published_at' => now(),
        ]);
    }

    private function configureVk(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://mskba.ru',
            'vk.wall.enabled' => true,
            'vk.wall.group_id' => 12345,
            'vk.wall.user_token' => 'photo-token',
            'vk.wall.community_token' => 'wall-token',
            'vk.wall.api_version' => '5.199',
        ]);
    }

    public function test_explicit_selection_posts_once_with_direct_material_url(): void
    {
        $this->configureVk();
        Queue::fake();
        Http::fake(['https://api.vk.com/method/wall.post' => Http::response(['response' => ['post_id' => 42]])]);
        Http::preventStrayRequests();
        $item = $this->item();
        $manager = app(VkContentPublicationManager::class);
        $manager->sync($item, false);
        $this->assertDatabaseCount('vk_content_publications', 0);

        $manager->sync($item, true);
        $row = VkContentPublication::query()->sole();
        $job = new PublishVkContentJob($row->id);
        $job->handle(app(VkWallPublisher::class));
        $this->assertSame(42, $row->fresh()->post_id);
        $manager->sync($item, true);
        $job->handle(app(VkWallPublisher::class));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['owner_id'] == -12345
            && $request['from_group'] == 1
            && $request['access_token'] === 'wall-token'
            && str_contains($request['message'], $item->publicUrl()));
        $this->assertFalse($item->fresh()->publish_in_telegram);
    }

    public function test_staging_never_posts_to_live_wall_and_drafts_are_ignored(): void
    {
        $this->configureVk();
        Queue::fake();
        Http::fake();
        $item = $this->item();
        $manager = app(VkContentPublicationManager::class);
        config(['app.env' => 'staging']);
        $manager->sync($item, true);
        $this->assertDatabaseCount('vk_content_publications', 0);

        $this->configureVk();
        $item->update(['status' => ContentStatusEnum::DRAFT]);
        $manager->sync($item, true);
        $this->assertDatabaseCount('vk_content_publications', 0);
        Http::assertNothingSent();
    }

    public function test_uncertain_result_is_not_reposted_on_save(): void
    {
        $this->configureVk();
        Queue::fake();
        Http::fake(['https://api.vk.com/method/wall.post' => Http::response([], 503)]);
        $item = $this->item();
        $manager = app(VkContentPublicationManager::class);
        $manager->sync($item, true);
        $row = VkContentPublication::query()->sole();
        $job = new PublishVkContentJob($row->id);
        $job->handle(app(VkWallPublisher::class));
        $this->assertSame('unknown', $row->fresh()->status);
        $manager->sync($item, true);
        $job->handle(app(VkWallPublisher::class));
        Http::assertSentCount(1);
    }
}
