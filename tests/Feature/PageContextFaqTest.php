<?php

namespace Tests\Feature;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Database\Seeders\PageFaqContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class PageContextFaqTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_catalog_has_unique_types_and_valid_related_keys(): void
    {
        $types = config('faq-page-types');
        $this->assertCount(89, $types);
        foreach ($types as $key => $entry) {
            $this->assertNotEmpty($entry['title'], $key);
            $this->assertNotEmpty($entry['about'], $key);
            $this->assertNotEmpty($entry['features'], $key);
            foreach ($entry['related'] as $related) {
                $this->assertArrayHasKey($related, $types, $key);
            }
        }
    }

    public function test_initial_seed_is_idempotent_and_page_help_requires_publication(): void
    {
        User::factory()->create();
        $this->seed(PageFaqContentSeeder::class);

        $item = ContentItem::query()->where('system_key', 'faq.page.venues.detail')->firstOrFail();
        $this->getJson('/faq/help-content?page_key=venues.detail')
            ->assertOk()
            ->assertJsonPath('page_help.title', 'Страница площадки')
            ->assertJsonPath('page_help.key', 'venues.detail');

        $item->update(['short_description' => 'Изменено редактором']);
        $this->seed(PageFaqContentSeeder::class);
        $this->assertSame('Изменено редактором', $item->fresh()->short_description);

        $item->update(['status' => 'draft']);
        $this->getJson('/faq/help-content?page_key=venues.detail')
            ->assertOk()
            ->assertJsonPath('page_help', null);
    }

    public function test_mskba_app_home_exposes_one_faq_dialog_and_floating_button(): void
    {
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $this->withoutVite();

        $response = $this->get('/')->assertOk()
            ->assertSee('data-help-launcher', false)
            ->assertSee('data-help-page-key="home"', false)
            ->assertSee('data-help-page-tab="features"', false);

        $html = (string) $response->getContent();
        $this->assertSame(1, substr_count($html, 'data-context-help-dialog'));
        $this->assertSame(1, substr_count($html, 'data-help-launcher'));
    }
}
