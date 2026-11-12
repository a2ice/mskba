<?php

namespace Tests\Feature\Content;

use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class SeoAppThemeMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'themes.active' => 'mskba_app',
            'seo.indexable_hosts' => ['mskba.ru', 'www.mskba.ru'],
        ]);

        // AppServiceProvider resolves theme:: views on boot, before a test can
        // switch config. Point this request at the actual app-theme templates.
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    public function test_app_theme_uses_production_seo_defaults_and_russian_language(): void
    {
        $this->get('https://mskba.ru/venues')
            ->assertOk()
            ->assertSee('<html lang="ru"', false)
            ->assertSee('<title>Баскетбольные площадки Москвы — MSKBA</title>', false)
            ->assertSee('name="description" content="Каталог баскетбольных площадок и залов Москвы:', false)
            ->assertSee('<link rel="canonical" href="https://mskba.ru/venues">', false)
            ->assertSee('property="og:site_name" content="MSKBA"', false)
            ->assertSee('name="twitter:card" content="summary_large_image"', false)
            ->assertSee('name="yandex-verification"', false)
            ->assertDontSee('<meta name="robots" content="noindex', false);
    }

    public function test_app_theme_home_keeps_organization_schema(): void
    {
        $this->get('https://mskba.ru/')
            ->assertOk()
            ->assertSee('"@type":"SportsOrganization"', false)
            ->assertSee('"@type":"WebSite"', false);
    }

    public function test_dev_host_is_noindex_in_both_html_and_header(): void
    {
        $this->get('https://dev.mskba.ru/venues')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false);
    }

    public function test_dev_host_hides_production_metrika_even_if_counter_is_misconfigured(): void
    {
        config(['services.yandex_metrika.id' => '12345678']);

        $this->get('https://dev.mskba.ru/venues')
            ->assertOk()
            ->assertDontSee('mc.yandex.ru/metrika/tag.js', false)
            ->assertDontSee('mc.yandex.ru/watch/12345678', false);
    }

    public function test_production_host_uses_same_metrika_id_once(): void
    {
        config(['services.yandex_metrika.id' => '12345678']);

        $response = $this->get('https://mskba.ru/venues')
            ->assertOk()
            ->assertSee('ym(12345678, "init"', false)
            ->assertSee('https://mc.yandex.ru/watch/12345678', false);

        $this->assertSame(1, substr_count($response->getContent(), 'mc.yandex.ru/metrika/tag.js'));
        $this->assertFalse($response->headers->has('X-Robots-Tag'));
    }

    public function test_staging_sitemap_has_noindex_response_header(): void
    {
        $this->get('https://dev.mskba.ru/sitemap.xml')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
