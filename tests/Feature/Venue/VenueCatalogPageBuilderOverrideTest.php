<?php

namespace Tests\Feature\Venue;

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class VenueCatalogPageBuilderOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['themes.active' => 'mskba_app']);
        $this->app->forgetInstance(ThemeResolver::class);
        View::getFinder()->replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $this->withoutVite();
    }

    public function test_published_pagebuilder_venues_page_can_be_safely_switched_to_laravel_and_back(): void
    {
        $page = PageBuilderPage::query()->create([
            'name' => 'Каталог площадок', 'path' => '/venues',
            'draft_html' => '<h1>CMS VENUES LEGACY</h1>',
        ]);
        $publisher = app(PageBuilderPublisher::class);
        $publisher->publish($page);

        $this->assertSame('venues', $page->refresh()->published_route_name);
        $this->assertTrue($page->cms_override_enabled);
        $this->get('/venues')->assertOk()->assertSee('CMS VENUES LEGACY');

        $snapshot = $page->published_html;
        $publisher->setOverrideEnabled($page, false);

        $page->refresh();
        $this->assertFalse($page->cms_override_enabled);
        $this->assertSame($snapshot, $page->published_html);
        $this->get('/venues')->assertOk()
            ->assertSee('data-venue-infinite-catalog')
            ->assertSee('app-catalog-tools__views')
            ->assertDontSee('CMS VENUES LEGACY');
        $this->assertDatabaseHas('page_builder_revisions', [
            'entity_type' => 'page', 'entity_id' => $page->id, 'action' => 'override_off',
        ]);

        $publisher->setOverrideEnabled($page, true);
        $this->get('/venues')->assertOk()->assertSee('CMS VENUES LEGACY');
        $this->assertSame($snapshot, $page->refresh()->published_html);
    }
}
