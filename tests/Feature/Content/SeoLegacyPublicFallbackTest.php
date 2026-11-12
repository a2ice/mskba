<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class SeoLegacyPublicFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'themes.active' => 'mskba_app',
            'seo.indexable_hosts' => ['mskba.ru', 'www.mskba.ru'],
        ]);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $this->withoutVite();
    }

    public function test_app_theme_published_feed_catalog_is_not_a_soft_404(): void
    {
        $this->get('https://mskba.ru/feed')
            ->assertOk()
            ->assertSee('Новости')
            ->assertDontSee('MSKBA App theme')
            ->assertSee('<link rel="canonical" href="https://mskba.ru/feed">', false);
    }

    public function test_published_feed_article_retains_body_and_canonical_url(): void
    {
        $user = User::factory()->create();
        $content = ContentItem::query()->create([
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
            'type' => 'material',
            'title' => 'Московский баскетбол сегодня',
            'alias' => 'moskovskiy-basketbol-segodnya',
            'short_description' => 'Актуальные новости сообщества.',
            'full_description' => 'Уникальный индексируемый контент старой статьи.',
            'publish_in_feed' => true,
            'publish_in_telegram' => false,
            'feed_published_at' => now()->subDay(),
        ]);

        $this->get('https://mskba.ru/feed/'.$content->alias)
            ->assertOk()
            ->assertSee($content->title)
            ->assertSee('Уникальный индексируемый контент старой статьи.')
            ->assertDontSee('MSKBA App theme')
            ->assertSee('<link rel="canonical" href="https://mskba.ru/feed/'.$content->alias.'">', false);
    }

    public function test_legacy_page_restores_new_theme_namespace_for_subsequent_requests(): void
    {
        $this->get('https://mskba.ru/faq/welcome')
            ->assertOk()
            ->assertDontSee('MSKBA App theme');

        $this->get('https://mskba.ru/venues')
            ->assertOk()
            ->assertSee('id="mskba-app"', false)
            ->assertDontSee('MSKBA App theme');

        $this->assertSame('mskba_app', app(ThemeResolver::class)->active());
        $this->assertTrue(View::exists('theme::pages.venues.index'));
    }

    public function test_dev_stays_noindex_when_serving_legacy_published_pages(): void
    {
        $this->get('https://dev.mskba.ru/faq/welcome')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
