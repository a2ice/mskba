<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Application\Services\TelegramContentMessageBuilder;
use App\Modules\Telegram\Application\Services\TelegramMiniAppStartDestinationResolver;
use App\Modules\Telegram\Domain\Models\TelegramChat;
use App\Modules\Telegram\Domain\Models\TelegramContentPublication;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramContentPublicationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ContentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_editor_can_manage_content_without_access_to_full_admin_panel(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->get(route('admin.content'))
            ->assertOk()
            ->assertSee('Контент');

        $this->actingAs($editor)
            ->get(route('admin.users'))
            ->assertForbidden();
    }

    public function test_regular_user_cannot_manage_content(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);

        $this->actingAs($user)
            ->get(route('admin.content'))
            ->assertForbidden();
    }

    public function test_editor_can_publish_material_in_public_news_feed(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->post(route('admin.content.store'), [
                'title' => 'Добавьте игровые характеристики',
                'short_description' => 'Заполните профиль игрока.',
                'full_description' => 'Рост, амплуа и игровые характеристики помогают находить подходящие игры.',
                'type' => 'material',
                'link_url' => '/account/participation',
                'publish_in_feed' => '1',
                'publish_in_telegram' => '0',
            ])
            ->assertRedirect();

        $content = ContentItem::query()->sole();

        $this->assertSame('dobavte-igrovye-kharakteristiki', $content->alias);
        $this->assertNotNull($content->feed_published_at);

        $this->get(route('news.index'))
            ->assertOk()
            ->assertSee('Добавьте игровые характеристики');

        $this->get(route('news.show', $content->alias))
            ->assertOk()
            ->assertSee('Рост, амплуа и игровые характеристики')
            ->assertSee('data-mobile-section-sidebar', false)
            ->assertSee('news-article-section')
            ->assertSee('/account/participation', false);
    }

    public function test_user_content_can_target_all_users_and_renders_safe_markdown_links(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->post(route('admin.content.store'), [
                'title' => 'Покажи себя в игре',
                'short_description' => 'Заполните профиль игрока.',
                'full_description' => <<<'MARKDOWN'
Заполните игровые характеристики.

[Заполнить профиль игрока](/account/participation)

<script>alert('unsafe')</script>

[Опасная ссылка](javascript:alert('unsafe'))
MARKDOWN,
                'type' => 'user',
                'publish_in_feed' => '1',
                'publish_in_telegram' => '0',
            ])
            ->assertRedirect();

        $content = ContentItem::query()->sole();

        $this->assertNull($content->related_type);
        $this->assertNull($content->related_id);
        $this->assertNull($content->link_url);

        $this->get(route('news.show', $content->alias))
            ->assertOk()
            ->assertSee('Для пользователей')
            ->assertSee('<a href="/account/participation">Заполнить профиль игрока</a>', false)
            ->assertDontSee('<script>', false)
            ->assertDontSee('href="javascript:', false);
    }

    public function test_editor_can_publish_sanitized_html_with_page_metadata(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->post(route('admin.content.store'), [
                'title' => 'Безопасная HTML-публикация',
                'short_description' => 'Материал с форматированием.',
                'full_description' => <<<'HTML'
<h2 class="content-accent evil-class" onclick="alert('unsafe')">Главное</h2>
<p class="content-lead">Абзац с <strong>выделением</strong>.</p>
<script>alert('unsafe')</script>
<a href="javascript:alert('unsafe')">Опасная ссылка</a>
<a href="/account/participation">Открыть профиль</a>
<img src="https://example.com/image.webp" alt="Иллюстрация" onerror="alert('unsafe')">
HTML,
                'content_format' => ContentFormatEnum::SAFE_HTML->value,
                'meta_title' => 'HTML-публикация — MSKBA',
                'meta_description' => 'Описание страницы для поисковых систем.',
                'meta_keywords' => 'баскетбол, профиль игрока',
                'type' => 'material',
                'publish_in_feed' => '1',
                'publish_in_telegram' => '0',
            ])
            ->assertRedirect();

        $content = ContentItem::query()->sole();

        $this->assertSame(ContentFormatEnum::SAFE_HTML, $content->content_format);
        $this->assertStringContainsString('class="content-accent"', $content->full_description);
        $this->assertStringNotContainsString('evil-class', $content->full_description);
        $this->assertStringNotContainsString('onclick=', $content->full_description);
        $this->assertStringNotContainsString('<script', $content->full_description);
        $this->assertStringNotContainsString('javascript:', $content->full_description);
        $this->assertStringNotContainsString('onerror=', $content->full_description);

        $this->get(route('news.show', $content->alias))
            ->assertOk()
            ->assertSee('<h2 class="content-accent">Главное</h2>', false)
            ->assertSee('<p class="content-lead">Абзац с <strong>выделением</strong>.</p>', false)
            ->assertSee('href="/account/participation"', false)
            ->assertSee('<title>HTML-публикация — MSKBA</title>', false)
            ->assertSee('name="description" content="Описание страницы для поисковых систем."', false)
            ->assertSee('name="keywords" content="баскетбол, профиль игрока"', false)
            ->assertDontSee('onclick=', false)
            ->assertDontSee('javascript:', false)
            ->assertDontSee('onerror=', false)
            ->assertDontSee('evil-class', false);
    }

    public function test_user_content_can_optionally_target_one_user(): void
    {
        $editor = $this->editor();
        $target = User::factory()->create();

        $this->actingAs($editor)
            ->post(route('admin.content.store'), [
                'title' => 'Персональный материал',
                'short_description' => 'Краткое описание.',
                'full_description' => 'Полное описание.',
                'type' => 'user',
                'related_id' => $target->id,
                'publish_in_feed' => '0',
                'publish_in_telegram' => '0',
            ])
            ->assertRedirect();

        $content = ContentItem::query()->sole();

        $this->assertSame('user', $content->related_type);
        $this->assertSame($target->id, $content->related_id);
    }

    public function test_telegram_snippet_opens_published_material_in_mini_app_instead_of_action_link(): void
    {
        config()->set('telegram.bot_username', '@MSKBABot');

        $content = ContentItem::query()->create([
            'created_by_user_id' => $this->editor()->id,
            'updated_by_user_id' => User::query()->firstOrFail()->id,
            'type' => 'user',
            'title' => 'Покажи себя в игре',
            'alias' => 'pokazhi-sebya-v-igre',
            'short_description' => 'Заполните профиль.',
            'full_description' => '[Перейти к профилю](/account/participation)',
            'link_url' => '/account/participation',
            'publish_in_feed' => true,
            'publish_in_telegram' => true,
            'feed_published_at' => now(),
        ]);

        $buttonUrl = app(TelegramContentMessageBuilder::class)
            ->replyMarkup($content)['inline_keyboard'][0][0]['url'];

        $this->assertSame(
            "https://t.me/MSKBABot?startapp=content_{$content->id}",
            $buttonUrl,
        );
        $this->assertSame(
            route('news.show', $content->alias, false),
            app(TelegramMiniAppStartDestinationResolver::class)->resolve("content_{$content->id}"),
        );
    }

    public function test_telegram_chats_are_persisted_and_queued_independently_from_feed(): void
    {
        Queue::fake();
        $editor = $this->editor();
        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567890,
            'title' => 'Редакционный чат',
            'type' => 'supergroup',
            'is_active' => true,
            'publishes_coordination' => false,
        ]);

        $this->actingAs($editor)
            ->post(route('admin.content.store'), [
                'title' => 'Материал только для Telegram',
                'short_description' => 'Краткое описание.',
                'full_description' => 'Полный текст материала.',
                'type' => 'material',
                'publish_in_feed' => '0',
                'publish_in_telegram' => '1',
                'telegram_chat_ids' => [$chat->id],
            ])
            ->assertRedirect();

        $content = ContentItem::query()->sole();

        $this->assertFalse($content->publish_in_feed);
        $this->assertTrue($content->publish_in_telegram);
        $this->assertDatabaseHas('telegram_content_publications', [
            'content_item_id' => $content->id,
            'chat_id' => $chat->id,
            'is_enabled' => true,
            'status' => 'pending',
        ]);
        Queue::assertPushed(SyncTelegramContentPublicationJob::class);
        $this->get(route('news.show', $content->alias))->assertNotFound();
    }

    public function test_content_with_cover_is_sent_to_telegram_as_photo(): void
    {
        $this->configureTelegram();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 501],
            ]),
        ]);
        [$content, $publication] = $this->contentPublication();

        $this->storeCover($content, 'content/1/cover.webp');

        app()->call([new SyncTelegramContentPublicationJob($publication->id), 'handle']);

        $this->assertDatabaseHas('telegram_content_publications', [
            'id' => $publication->id,
            'message_id' => 501,
            'message_type' => 'photo',
            'status' => 'published',
        ]);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')
            && $this->multipartPart($request->data(), 'photo')['filename'] === "content-{$content->id}.jpg"
            && str_contains($this->multipartPart($request->data(), 'caption')['contents'], '<b>Материал с обложкой</b>'));
    }

    public function test_existing_text_publication_is_replaced_when_cover_is_added(): void
    {
        $this->configureTelegram();
        Http::fake([
            'https://api.telegram.org/*/deleteMessage' => Http::response(['ok' => true, 'result' => true]),
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 502],
            ]),
        ]);
        [$content, $publication] = $this->contentPublication([
            'message_id' => 401,
            'message_type' => 'text',
            'status' => 'published',
        ]);

        $this->storeCover($content, 'content/1/replacement.webp');

        app()->call([new SyncTelegramContentPublicationJob($publication->id), 'handle']);

        $this->assertDatabaseHas('telegram_content_publications', [
            'id' => $publication->id,
            'message_id' => 502,
            'message_type' => 'photo',
            'status' => 'published',
        ]);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/deleteMessage')
            && $request['message_id'] === 401);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')
            && $this->multipartPart($request->data(), 'photo')['filename'] === "content-{$content->id}.jpg");
    }

    public function test_existing_photo_publication_is_edited_in_place(): void
    {
        $this->configureTelegram();
        Http::fake([
            'https://api.telegram.org/*/editMessageMedia' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 503],
            ]),
        ]);
        [$content, $publication] = $this->contentPublication([
            'message_id' => 503,
            'message_type' => 'photo',
            'status' => 'published',
        ]);

        $this->storeCover($content, 'content/1/updated.webp');

        app()->call([new SyncTelegramContentPublicationJob($publication->id), 'handle']);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/editMessageMedia')
            && $this->multipartPart($request->data(), 'photo')['filename'] === "content-{$content->id}.jpg"
            && $this->multipartJsonPart($request->data(), 'media')['media'] === 'attach://photo'
            && str_contains($this->multipartJsonPart($request->data(), 'media')['caption'], 'Материал с обложкой'));
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/deleteMessage'));
    }

    private function configureTelegram(): void
    {
        Storage::fake('public');
        config()->set('app.url', 'http://localhost');
        config()->set('telegram.bot_token', 'test-token');
        config()->set('telegram.bot_username', 'MSKBABot');
        config()->set('telegram.api_ip', null);
        config()->set('telegram.proxy', null);
    }

    private function storeCover(ContentItem $content, string $path): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );

        $this->assertIsString($png);
        Storage::disk('public')->put($path, $png);
        $content->media()->create([
            'collection' => 'content_cover',
            'disk' => 'public',
            'path' => $path,
            'mime' => 'image/webp',
            'size' => strlen($png),
            'sort_order' => 0,
            'is_featured' => true,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $parts
     * @return array<string, mixed>
     */
    private function multipartPart(array $parts, string $name): array
    {
        return collect($parts)->firstWhere('name', $name) ?? [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $parts
     * @return array<string, mixed>
     */
    private function multipartJsonPart(array $parts, string $name): array
    {
        return json_decode($this->multipartPart($parts, $name)['contents'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $publicationAttributes
     * @return array{ContentItem, TelegramContentPublication}
     */
    private function contentPublication(array $publicationAttributes = []): array
    {
        $editor = $this->editor();
        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567890,
            'title' => 'Редакционный чат',
            'type' => 'supergroup',
            'is_active' => true,
            'publishes_coordination' => false,
        ]);
        $content = ContentItem::query()->create([
            'created_by_user_id' => $editor->id,
            'updated_by_user_id' => $editor->id,
            'type' => 'material',
            'title' => 'Материал с обложкой',
            'alias' => 'material-s-oblozhkoi',
            'short_description' => 'Краткое описание.',
            'full_description' => 'Полное описание.',
            'publish_in_feed' => false,
            'publish_in_telegram' => true,
        ]);
        $publication = TelegramContentPublication::query()->create([
            'content_item_id' => $content->id,
            'chat_id' => $chat->id,
            'is_enabled' => true,
            'status' => 'pending',
            ...$publicationAttributes,
        ]);

        return [$content, $publication];
    }

    private function editor(): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::EDITOR,
        ]);
    }
}
