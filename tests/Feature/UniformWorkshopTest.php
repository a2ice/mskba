<?php

namespace Tests\Feature;

use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Database\Seeders\FaqContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UniformWorkshopTest extends TestCase
{
    use RefreshDatabase;

    public function test_workshop_page_is_public_and_shows_the_shared_mount(): void
    {
        // CI executes PHP tests before Vite builds newly introduced entrypoints.
        $this->withoutVite();

        $this->get('/uniforms/workshop')
            ->assertOk()
            ->assertSee('Мастерская формы')
            ->assertSee('data-mskba-uniform-workshop-page', false)
            ->assertSee('Мастерская работает в демонстрационном режиме.');
    }

    public function test_uniform_faq_articles_are_published_in_their_sections_and_preserve_editor_changes(): void
    {
        User::factory()->create(['system_role' => UserSystemRoleEnum::SUPERADMIN]);
        $this->seed(FaqContentSeeder::class);

        foreach (['selection', 'workshop', 'production'] as $key) {
            $article = ContentItem::query()->where('system_key', 'faq.uniforms.'.$key)->sole();
            $this->assertSame(ContentTypeEnum::FAQ, $article->type);
            $this->get('/faq/faq-uniform-'.$key)->assertOk();
            $this->getJson('/faq/help-content?section=uniforms&article=faq-uniform-'.$key)
                ->assertOk()
                ->assertJsonPath('article.alias', 'faq-uniform-'.$key);
        }
        $this->getJson('/faq/help-content?section=partnership&article=faq-uniform-partnership')
            ->assertOk()
            ->assertJsonPath('article.alias', 'faq-uniform-partnership');

        $existing = ContentItem::query()->where('system_key', 'faq.uniforms.workshop')->sole();
        $existing->update(['title' => 'Текст после редактирования']);
        $this->seed(FaqContentSeeder::class);
        $this->assertSame('Текст после редактирования', $existing->fresh()->title);
        $this->assertSame(4, ContentItem::query()->where('system_key', 'like', 'faq.uniforms.%')->count());
    }
}
