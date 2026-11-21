<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class EditorialNewsAgentApiTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/internal/editorial-news';

    private function dev(): User
    {
        return User::factory()->create([
            'username' => 'dev',
            'system_role' => UserSystemRoleEnum::SYSTEM,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
    }

    private function payload(): array
    {
        return ['alias' => 'winter-basketball', 'title' => 'Перебираемся в залы',
            'summary' => 'На улице холодно.', 'content' => '[Найти зал](/venues)'];
    }

    private function token(User $dev, array $abilities, string $name = 'mskba-editorial-news:test'): string
    {
        return $dev->createToken($name, $abilities, now()->addDay())->plainTextToken;
    }

    public function test_draft_with_cover_requires_separate_permission_to_publish(): void
    {
        Storage::fake('public');
        $dev = $this->dev();
        $this->withToken($this->token($dev, ['editorial-news:write']))
            ->post(self::URI, [...$this->payload(), 'cover' => UploadedFile::fake()->image('meme.jpg', 400, 520)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.has_cover', true)->assertJsonPath('data.url', null);
        $item = ContentItem::query()->sole();
        Storage::disk('public')->assertExists($item->cover()->sole()->path);
        $this->assertFalse($item->publish_in_feed);
        $this->postJson(self::URI.'/winter-basketball/publish')->assertForbidden();
        app('auth')->forgetGuards();

        $this->withToken($this->token($dev, ['editorial-news:write', 'editorial-news:publish']))
            ->postJson(self::URI.'/winter-basketball/publish')
            ->assertOk()->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.url', $item->publicUrl());
        $this->assertTrue($item->fresh()->publish_in_feed);
        $this->assertFalse($item->fresh()->publish_in_telegram);
        $publishedAt = $item->fresh()->feed_published_at;
        $this->postJson(self::URI.'/winter-basketball/publish')->assertOk();
        $this->assertTrue($publishedAt->equalTo($item->fresh()->feed_published_at));
        $this->assertDatabaseCount('content_items', 1);
    }

    public function test_existing_system_update_token_and_wildcard_cannot_use_editorial_api(): void
    {
        $this->postJson(self::URI, $this->payload())->assertUnauthorized();
        app('auth')->forgetGuards();
        $dev = $this->dev();
        $this->withToken($this->token($dev, ['project-updates:write'], 'mskba-project-updates:test'))
            ->postJson(self::URI, $this->payload())->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($this->token($dev, ['*']))
            ->postJson(self::URI, $this->payload())->assertForbidden();
        $this->assertDatabaseCount('content_items', 0);
    }

    public function test_retries_do_not_duplicate_news_and_system_materials_cannot_be_published(): void
    {
        $dev = $this->dev();
        $this->withToken($this->token($dev, ['editorial-news:write', 'editorial-news:publish']))
            ->postJson(self::URI, $this->payload())->assertCreated();
        $this->postJson(self::URI, $this->payload())->assertUnprocessable();
        $this->getJson(self::URI.'/winter-basketball')->assertOk();
        $item = ContentItem::query()->sole();
        $item->update(['type' => 'system_update']);
        $this->postJson(self::URI.'/winter-basketball/publish')->assertNotFound();
        $this->assertFalse($item->fresh()->publish_in_feed);
    }
}
