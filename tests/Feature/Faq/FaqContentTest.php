<?php

namespace Tests\Feature\Faq;

use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Database\Seeders\FaqContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class FaqContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_can_create_faq_with_tags_and_search_uses_tags_only(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->post(route('admin.content.store'), [
                'title' => 'Как добавить тестовую площадку',
                'short_description' => 'Инструкция по добавлению площадки.',
                'full_description' => '<p>Полная инструкция.</p>',
                'content_format' => ContentFormatEnum::SAFE_HTML->value,
                'type' => ContentTypeEnum::FAQ->value,
                'tags' => 'площадка, добавление площадки, адрес площадки',
                'publish_in_feed' => '1',
                'publish_in_telegram' => '0',
            ])
            ->assertRedirect();

        $content = ContentItem::query()->with('tags')->sole();

        $this->assertSame(ContentTypeEnum::FAQ, $content->type);
        $this->assertFalse($content->publish_in_feed);
        $this->assertFalse($content->publish_in_telegram);
        $this->assertNull($content->feed_published_at);
        $this->assertEqualsCanonicalizing(
            ['площадка', 'добавление площадки', 'адрес площадки'],
            $content->tags->pluck('name')->all(),
        );

        $this->get(route('faq.show', $content->alias))
            ->assertOk()
            ->assertSee('Как добавить тестовую площадку')
            ->assertSee('Вводите именно суть, а не сам вопрос');

        $this->getJson(route('faq.search', ['q' => 'площад']))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Как добавить тестовую площадку']);

        $this->getJson(route('faq.search', ['q' => 'как']))
            ->assertOk()
            ->assertExactJson(['results' => []]);

        $this->getJson(route('faq.search', ['q' => 'полная инструкция']))
            ->assertOk()
            ->assertExactJson(['results' => []]);
    }

    public function test_content_editor_starts_with_shortcode_help(): void
    {
        $this->actingAs($this->editor())
            ->get(route('admin.content.create'))
            ->assertOk()
            ->assertSee('Shortcodes редактора')
            ->assertSee('[image id=', false)
            ->assertSee('[popup type=', false)
            ->assertSee('[creation_requirements topic=', false)
            ->assertSee('[anchor id=', false);
    }

    public function test_creation_requirements_shortcode_renders_current_business_rules(): void
    {
        $editor = $this->editor();
        $content = ContentItem::query()->create([
            'created_by_user_id' => $editor->id,
            'updated_by_user_id' => $editor->id,
            'type' => ContentTypeEnum::FAQ,
            'title' => 'Создание мероприятия',
            'alias' => 'faq-event-requirements-test',
            'short_description' => 'Условия создания мероприятия.',
            'full_description' => '[creation_requirements topic="events"]',
            'content_format' => ContentFormatEnum::SAFE_HTML,
            'publish_in_feed' => false,
            'publish_in_telegram' => false,
        ]);

        $requirement = config('creation-guides.events.requirements.0.text');

        $this->get(route('faq.show', $content->alias))
            ->assertOk()
            ->assertSee('Условия создания')
            ->assertSee($requirement)
            ->assertSee(route('faq.welcome').'#contact-confirmation', false);
    }

    public function test_editor_can_upload_inline_image_and_render_it_with_shortcode(): void
    {
        Storage::fake('public');
        $editor = $this->editor();
        $content = ContentItem::query()->create([
            'created_by_user_id' => $editor->id,
            'updated_by_user_id' => $editor->id,
            'type' => ContentTypeEnum::FAQ,
            'title' => 'FAQ с изображением',
            'alias' => 'faq-with-image',
            'short_description' => 'Инструкция с иллюстрацией.',
            'full_description' => '<p>Инструкция.</p>',
            'content_format' => ContentFormatEnum::SAFE_HTML,
            'publish_in_feed' => false,
            'publish_in_telegram' => false,
        ]);

        $this->actingAs($editor)
            ->post(route('admin.content.images.store', $content->alias), [
                'image' => UploadedFile::fake()->image('faq.jpg', 800, 500),
                'title' => 'Экран подтверждения',
                'description' => 'Пример экрана в личном кабинете',
            ])
            ->assertRedirect();

        $media = $content->inlineImages()->sole();
        Storage::disk('public')->assertExists($media->path);

        $content->update([
            'full_description' => '[image id="'.$media->id.'" view="wide"]',
        ]);

        $this->get(route('faq.show', $content->alias))
            ->assertOk()
            ->assertSee('content-image--wide', false)
            ->assertSee('/storage/'.$media->path, false)
            ->assertSee('alt="Экран подтверждения"', false)
            ->assertSee('Пример экрана в личном кабинете');
    }

    public function test_faq_seeder_is_idempotent_and_does_not_overwrite_editor_changes(): void
    {
        $this->editor(UserSystemRoleEnum::SUPERADMIN);

        $this->seed(FaqContentSeeder::class);

        $content = ContentItem::query()
            ->where('system_key', 'faq.creation.venues')
            ->firstOrFail();
        $content->update(['title' => 'Отредактированная инструкция']);

        $this->seed(FaqContentSeeder::class);

        $this->assertSame(
            'Отредактированная инструкция',
            $content->fresh()->title,
        );
        $this->assertSame(7, ContentItem::query()->where('type', ContentTypeEnum::FAQ)->count());
    }

    private function editor(UserSystemRoleEnum $role = UserSystemRoleEnum::EDITOR): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
        ]);
    }
}
