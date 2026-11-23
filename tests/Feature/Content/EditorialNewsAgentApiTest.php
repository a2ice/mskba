<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramChat;
use App\Modules\Telegram\Domain\Models\TelegramContentPublication;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramContentPublicationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_social_publication_is_explicit_scoped_and_idempotent(): void
    {
        Storage::fake('public');
        Queue::fake();
        Http::fake();
        config([
            'app.env' => 'production', 'app.url' => 'https://mskba.ru',
            'telegram.bot_token' => 'test-bot',
            'vk.wall.enabled' => true, 'vk.wall.group_id' => 174334075,
            'vk.wall.community_token' => 'test-community', 'vk.wall.user_token' => 'test-photo',
            'vk.wall.user_token_expires_at' => now()->addHour()->timestamp,
        ]);
        $official = TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567891, 'title' => 'MSKBA Official', 'type' => 'supergroup', 'is_active' => true,
        ]);
        $other = TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567892, 'title' => 'Other', 'type' => 'supergroup', 'is_active' => true,
        ]);
        $inactive = TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567893, 'title' => 'Inactive', 'type' => 'supergroup', 'is_active' => false,
        ]);
        $dev = $this->dev();
        $selected = ['telegram_chat_ids' => [$official->id], 'publish_in_vk' => true, 'vk_group_id' => 174334075];
        $this->withToken($this->token($dev, ['editorial-news:write', 'editorial-news:publish']))
            ->post(self::URI, [...$this->payload(), 'cover' => UploadedFile::fake()->image('cover.jpg', 400, 520)], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->postJson(self::URI.'/winter-basketball/publish', $selected)->assertForbidden();
        $this->getJson(self::URI.'/destinations/list')->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($this->token($dev, ['editorial-news:write', 'editorial-news:publish', 'editorial-news:social']))
            ->getJson(self::URI.'/destinations/list')
            ->assertOk()->assertJsonCount(2, 'data.telegram')
            ->assertJsonPath('data.vk.group_id', 174334075);
        $this->postJson(self::URI.'/winter-basketball/publish', [
            ...$selected, 'telegram_chat_ids' => [$inactive->id],
        ])->assertUnprocessable();
        $this->postJson(self::URI.'/winter-basketball/publish', [
            ...$selected, 'vk_group_id' => 123,
        ])->assertUnprocessable();
        $this->assertFalse(ContentItem::query()->sole()->publish_in_feed);
        $this->postJson(self::URI.'/winter-basketball/publish', $selected)
            ->assertOk()->assertJsonPath('data.status', 'published')
            ->assertJsonCount(1, 'data.telegram_publications')
            ->assertJsonPath('data.telegram_publications.0.chat_id', $official->id)
            ->assertJsonPath('data.telegram_publications.0.status', 'pending')
            ->assertJsonPath('data.vk_publication.group_id', 174334075)
            ->assertJsonPath('data.vk_publication.status', 'pending');
        $this->postJson(self::URI.'/winter-basketball/publish', $selected)->assertOk();
        $this->postJson(self::URI.'/winter-basketball/publish')->assertOk();
        $this->postJson(self::URI.'/winter-basketball/publish', [
            ...$selected, 'telegram_chat_ids' => [$other->id],
        ])->assertConflict();
        $this->getJson(self::URI.'/winter-basketball')->assertOk()
            ->assertJsonPath('data.telegram_publications.0.chat_id', $official->id);
        $this->assertDatabaseCount('telegram_content_publications', 1);
        $this->assertDatabaseCount('vk_content_publications', 1);
        Http::assertNothingSent();
    }

    public function test_social_publication_rejects_dev_and_vk_without_cover(): void
    {
        Queue::fake();
        Http::fake();
        $dev = $this->dev();
        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567891, 'title' => 'Official', 'type' => 'supergroup', 'is_active' => true,
        ]);
        config(['app.env' => 'staging', 'app.url' => 'https://dev.mskba.ru', 'telegram.bot_token' => 'test-bot']);
        $this->withToken($this->token($dev, ['editorial-news:write', 'editorial-news:publish', 'editorial-news:social']))
            ->postJson(self::URI, $this->payload())->assertCreated();
        $this->getJson(self::URI.'/destinations/list')->assertOk()
            ->assertJsonCount(0, 'data.telegram')->assertJsonPath('data.vk.available', false);
        $this->postJson(self::URI.'/winter-basketball/publish', ['telegram_chat_ids' => [$chat->id]])->assertUnprocessable();
        config([
            'app.env' => 'production', 'app.url' => 'https://mskba.ru',
            'vk.wall.enabled' => true, 'vk.wall.group_id' => 174334075,
            'vk.wall.community_token' => 'test-community', 'vk.wall.user_token' => 'test-photo',
            'vk.wall.user_token_expires_at' => now()->addHour()->timestamp,
        ]);
        $this->postJson(self::URI.'/winter-basketball/publish', [
            'publish_in_vk' => true, 'vk_group_id' => 174334075,
        ])->assertUnprocessable();
        $this->assertFalse(ContentItem::query()->sole()->publish_in_feed);
        $this->assertDatabaseCount('telegram_content_publications', 0);
        $this->assertDatabaseCount('vk_content_publications', 0);
        Http::assertNothingSent();
    }

    public function test_expired_vk_authorization_requires_explicit_deferral(): void
    {
        Storage::fake('public');
        Queue::fake();
        Http::fake();
        config([
            'app.env' => 'production', 'app.url' => 'https://mskba.ru',
            'vk.wall.enabled' => true, 'vk.wall.group_id' => 174334075,
            'vk.wall.community_token' => 'test-community', 'vk.wall.user_token' => 'test-photo',
            'vk.wall.user_token_expires_at' => now()->subMinute()->timestamp,
        ]);
        $dev = $this->dev();
        $this->withToken($this->token($dev, ['editorial-news:write', 'editorial-news:publish', 'editorial-news:social']))
            ->post(self::URI, [...$this->payload(), 'cover' => UploadedFile::fake()->image('cover.jpg', 400, 520)], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->getJson(self::URI.'/destinations/list')->assertOk()
            ->assertJsonPath('data.vk.available', false)
            ->assertJsonPath('data.vk.can_defer', true)
            ->assertJsonPath('data.vk.group_id', 174334075);
        $selection = ['publish_in_vk' => true, 'vk_group_id' => 174334075];
        $this->postJson(self::URI.'/winter-basketball/publish', $selection)->assertUnprocessable();
        $this->assertFalse(ContentItem::query()->sole()->publish_in_feed);
        $this->postJson(self::URI.'/winter-basketball/publish', [...$selection, 'defer_vk_until_authorized' => true])
            ->assertOk()->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.vk_publication.status', 'awaiting_auth')
            ->assertJsonPath('data.vk_publication.post_id', null);
        $this->assertDatabaseCount('vk_content_publications', 1);
        Http::assertNothingSent();
    }

    public function test_production_bridge_token_has_separate_lifetime_and_exact_scopes(): void
    {
        $dev = $this->dev();
        $abilities = ['editorial-news:write', 'editorial-news:publish', 'editorial-news:social'];
        config(['app.env' => 'production', 'app.url' => 'https://mskba.ru']);
        $ordinary = $dev->createToken('mskba-editorial-news:filament-test', $abilities, now()->addDays(20))->plainTextToken;
        $this->withToken($ordinary)->postJson(self::URI, $this->payload())->assertForbidden();
        app('auth')->forgetGuards();
        $bridge = $dev->createToken('mskba-editorial-news:bridge-test', $abilities, now()->addDays(29))->plainTextToken;
        $this->withToken($bridge)->postJson(self::URI, $this->payload())->assertCreated();
        config(['app.env' => 'staging', 'app.url' => 'https://dev.mskba.ru']);
        $this->getJson(self::URI.'/winter-basketball')->assertForbidden();
        config(['app.env' => 'production', 'app.url' => 'https://mskba.ru']);
        app('auth')->forgetGuards();
        $broad = $dev->createToken('mskba-editorial-news:bridge-test', [...$abilities, '*'], now()->addDays(20))->plainTextToken;
        $this->withToken($broad)->getJson(self::URI.'/winter-basketball')->assertForbidden();
        app('auth')->forgetGuards();
        $long = $dev->createToken('mskba-editorial-news:bridge-test', $abilities, now()->addDays(31))->plainTextToken;
        $this->withToken($long)->getJson(self::URI.'/winter-basketball')->assertForbidden();
    }


    public function test_editorial_api_persists_seo_defaults_and_explicit_fields(): void
    {
        $dev = $this->dev();
        $this->withToken($this->token($dev, ['editorial-news:write']))
            ->postJson(self::URI, $this->payload())
            ->assertCreated();
        $first = ContentItem::query()->where('alias', 'winter-basketball')->sole();
        $this->assertSame('Перебираемся в залы', $first->meta_title);
        $this->assertSame('На улице холодно.', $first->meta_description);
        $this->assertSame('баскетбол, Москва, MSKBA', $first->meta_keywords);

        $this->postJson(self::URI, [
            ...$this->payload(),
            'alias' => 'another-basketball-news',
            'meta_title' => 'Играем в зале | MSKBA',
            'meta_description' => 'Баскетбол рядом с вами.',
            'meta_keywords' => 'баскетбол, игры, спорт',
        ])->assertCreated();
        $other = ContentItem::query()->where('alias', 'another-basketball-news')->sole();
        $this->assertSame('Играем в зале | MSKBA', $other->meta_title);
        $this->assertSame('Баскетбол рядом с вами.', $other->meta_description);
        $this->assertSame('баскетбол, игры, спорт', $other->meta_keywords);
        $this->postJson(self::URI, [...$this->payload(), 'alias' => 'invalid-seo',
            'meta_description' => str_repeat('x', 321),
        ])->assertUnprocessable();
    }

    public function test_telegram_retry_is_scoped_and_rejects_uncertain_delivery(): void
    {
        Queue::fake();
        config([
            'app.env' => 'production', 'app.url' => 'https://mskba.ru',
            'telegram.bot_token' => 'test-bot',
        ]);
        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567891, 'title' => 'Основной чат MSKBA',
            'type' => 'supergroup', 'is_active' => true,
        ]);
        $dev = $this->dev();
        $this->withToken($this->token($dev, [
            'editorial-news:write', 'editorial-news:publish', 'editorial-news:social',
        ]))->postJson(self::URI, $this->payload())->assertCreated();

        $route = self::URI.'/winter-basketball/telegram/'.$chat->id.'/retry';
        $this->postJson(self::URI.'/winter-basketball/publish', [
            'telegram_chat_ids' => [$chat->id],
        ])->assertOk();

        $record = TelegramContentPublication::query()->sole();
        $record->forceFill([
            'status' => 'failed', 'message_id' => null,
            'last_error' => 'Telegram API returned HTTP 403. Forbidden: bot was kicked from the group',
        ])->save();

        $this->getJson(self::URI.'/winter-basketball')
            ->assertOk()
            ->assertJsonPath('data.telegram_publications.0.error_code', 'bot_access_denied')
            ->assertJsonPath('data.telegram_publications.0.retry_safe', true)
            ->assertDontSee('bot was kicked');

        $this->postJson($route)->assertOk()
            ->assertJsonPath('data.telegram_publications.0.status', 'pending');
        $this->postJson($route)->assertOk(); // pending does not dispatch twice
        Queue::assertPushed(SyncTelegramContentPublicationJob::class, 2);

        $record->refresh()->forceFill([
            'status' => 'failed', 'message_id' => null,
            'last_error' => 'Telegram API connection failed.',
        ])->save();
        $this->getJson(self::URI.'/winter-basketball')
            ->assertJsonPath('data.telegram_publications.0.error_code', 'outcome_unknown')
            ->assertJsonPath('data.telegram_publications.0.retry_safe', false);
        $this->postJson($route)->assertConflict();
        Queue::assertPushed(SyncTelegramContentPublicationJob::class, 2);

        $record->refresh()->forceFill(['status' => 'published', 'message_id' => 99])->save();
        $this->postJson($route)->assertOk()
            ->assertJsonPath('data.telegram_publications.0.message_id', 99);
        Queue::assertPushed(SyncTelegramContentPublicationJob::class, 2);

        $this->postJson(self::URI.'/winter-basketball/telegram/99999/retry')->assertNotFound();
    }

    public function test_telegram_retry_requires_social_scope(): void
    {
        $dev = $this->dev();
        $this->withToken($this->token($dev, ['editorial-news:write', 'editorial-news:publish']))
            ->postJson(self::URI, $this->payload())->assertCreated();
        $this->postJson(self::URI.'/winter-basketball/telegram/1/retry')->assertForbidden();
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
