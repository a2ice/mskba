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
    public function test_venue_catalog_keeps_help_and_detail_shows_floating_create_and_rating(): void
    {
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $this->withoutVite();

        $venue = \App\Modules\Venue\Domain\Models\Venue::factory()->create([
            'status' => \App\Modules\Venue\Domain\Enums\VenueStatusEnum::CONFIRMED,
        ]);
        $this->get(route('venues'))->assertOk()
            ->assertDontSee('data-venue-floating-create', false)
            ->assertSee('data-help-launcher', false)
            ->assertSee('app-venues-catalog__create', false)
            ->assertSee('data-help-venue-create-template', false)
            ->assertSee('Мы приветствуем вклад каждого участника')
            ->assertSee('data-context-help-dialog', false);

        $url = route('venues.show', $venue->routeIdentifier());
        $this->get($url)->assertOk()
            ->assertSee('data-venue-floating-create', false)
            ->assertDontSee('data-help-launcher', false)
            ->assertSee('data-venue-heading-rating', false)
            ->assertDontSee('data-help-venue-create-template', false)
            ->assertSee('Пока нет оценок игроков');

        foreach ([[5, true], [4, true], [1, false]] as [$rating, $published]) {
            \App\Modules\Venue\Domain\Models\VenueReview::factory()->for($venue)->create([
                'rating' => $rating,
                'is_published' => $published,
                'published_at' => $published ? now() : null,
            ]);
        }
        $this->get($url)->assertOk()
            ->assertSee('Рейтинг площадки: 4.5 из 5 · оценок: 2')
            ->assertSee('data-context-help-dialog', false);
    }
}
