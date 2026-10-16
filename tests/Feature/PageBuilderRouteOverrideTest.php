<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Application\Services\PageBuilderRouteRegistry;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PageBuilderRouteOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function cmsPage(string $path, string $html = '<h1>CMS EVENTS VERSION</h1>'): PageBuilderPage
    {
        return PageBuilderPage::query()->create([
            'name' => 'Управляемая страница', 'path' => $path, 'draft_html' => $html,
            'meta_title' => 'CMS route override test',
        ]);
    }

    public function test_public_laravel_route_is_detected_without_manual_allowlist(): void
    {
        $registry = app(PageBuilderRouteRegistry::class);
        $events = $registry->inspect('/events');
        $this->assertTrue($events['eligible']);
        $this->assertSame('events.index', $events['route_name']);
        $this->assertSame('public_index', $events['type']);
        $this->assertStringContainsString('EventController', $events['action']);
        $this->assertTrue($events['conflict']);

        $new = $registry->inspect('/about-pagebuilder');
        $this->assertFalse($new['conflict']);
        $this->assertSame('static', $registry->forPublication('/about-pagebuilder')['type']);
    }

    public function test_existing_events_route_switches_only_after_page_publication_and_keeps_named_route(): void
    {
        $publisher = app(PageBuilderPublisher::class);
        $page = $this->cmsPage('/events');
        $this->withoutVite();

        $this->assertSame('/events', route('events.index', [], false));
        $before = app(PageBuilderRouteRegistry::class)->inspect('/events');
        $beforeMiddlewares = \Illuminate\Support\Facades\Route::getRoutes()
            ->match(\Illuminate\Http\Request::create('/events', 'GET'))->gatherMiddleware();
        $this->get('/events')->assertOk()->assertDontSee('CMS EVENTS VERSION');
        $after = app(PageBuilderRouteRegistry::class)->inspect('/events');
        $afterMiddlewares = \Illuminate\Support\Facades\Route::getRoutes()
            ->match(\Illuminate\Http\Request::create('/events', 'GET'))->gatherMiddleware();
        $this->assertSame($beforeMiddlewares, $afterMiddlewares, 'Middleware mutates across dispatch');
        $this->assertSame($before, $after, 'Маршрут не должен менять признаки допуска после первого GET.');
        $this->assertNull($page->published_at);

        $publisher->publish($page);
        $page->refresh();

        $this->assertSame('events.index', $page->published_route_name);
        $this->assertSame('public_index', $page->published_route_type);
        $this->assertTrue($page->cms_override_enabled);
        $this->get('/events')->assertOk()->assertSee('CMS EVENTS VERSION')
            ->assertSee('rel="canonical"', false);
        $this->get('/events?period=past')->assertOk()->assertSee('CMS EVENTS VERSION');
        $this->head('/events')->assertOk();
        $this->assertSame('/events', route('events.index', [], false));
    }

    public function test_rollback_to_original_laravel_controller_preserves_published_snapshot_and_can_be_reenabled(): void
    {
        $publisher = app(PageBuilderPublisher::class);
        $page = $this->cmsPage('/events');
        $publisher->publish($page);
        $this->withoutVite();

        $publisher->setOverrideEnabled($page, false);
        $page->refresh();
        $this->assertFalse($page->cms_override_enabled);
        $this->assertNotNull($page->published_at);
        $this->assertSame('<h1>CMS EVENTS VERSION</h1>', $page->published_html);
        $this->get('/events')->assertOk()->assertDontSee('CMS EVENTS VERSION');

        $publisher->setOverrideEnabled($page, true);
        $this->assertTrue($page->refresh()->cms_override_enabled);
        $this->get('/events')->assertOk()->assertSee('CMS EVENTS VERSION');
        $this->assertSame(['publish', 'override_off', 'override_on'],
            PageBuilderRevision::query()->where('entity_type', 'page')
                ->orderBy('id')->pluck('action')->all());
    }

    public function test_editing_draft_does_not_change_public_route_or_content_until_publish(): void
    {
        $publisher = app(PageBuilderPublisher::class);
        $page = $this->cmsPage('/events');
        $publisher->publish($page);
        $this->withoutVite();

        $page->update(['path' => '/different-route', 'draft_html' => '<h1>CHANGED DRAFT</h1>']);
        $this->get('/events')->assertOk()->assertSee('CMS EVENTS VERSION')->assertDontSee('CHANGED DRAFT');
        $this->get('/different-route')->assertNotFound();
        $this->assertSame('/events', $page->refresh()->published_path);

        $publisher->publish($page);
        $page->refresh();
        $this->assertNull($page->published_route_name);
        $this->assertSame('static', $page->published_route_type);
        $this->get('/events')->assertOk()->assertDontSee('CMS EVENTS VERSION');
        $this->get('/different-route')->assertOk()->assertSee('CHANGED DRAFT');
    }

    public function test_cms_only_page_is_available_via_fallback_without_route_declaration(): void
    {
        $page = $this->cmsPage('/open-gym');
        app(PageBuilderPublisher::class)->publish($page);
        $this->withoutVite();

        $this->assertNull($page->refresh()->published_route_name);
        $this->assertFalse($page->cms_override_enabled);
        $this->get('/open-gym')->assertOk()->assertSee('CMS EVENTS VERSION');
    }

    public function test_protected_system_and_dynamic_paths_are_never_publishable(): void
    {
        $publisher = app(PageBuilderPublisher::class);
        $registry = app(PageBuilderRouteRegistry::class);

        foreach (['/login', '/account', '/backoffice', '/api', '/auth',
            '/events/create', '/events/example/edit', '/events/foo', '/page-builder',
        ] as $path) {
            $this->assertFalse($registry->inspect($path)['eligible'], $path);
        }

        foreach (['/login', '/account', '/backoffice', '/api', '/auth'] as $path) {
            $page = $this->cmsPage($path);
            try {
                $publisher->publish($page);
                $this->fail('Protected system URL was accepted: '.$path);
            } catch (ValidationException) {
                $this->assertNull($page->refresh()->published_at);
            }
        }

        // Existing dynamic route and write operations cannot be replaced by
        // the /events collection route override.
        $page = $this->cmsPage('/events');
        $publisher->publish($page);
        $this->withoutVite();
        $this->get('/events/some-unregistered-event')->assertNotFound();
        $this->post('/events')->assertRedirect(); // Auth or permission middleware still runs.
    }

    public function test_toggle_cannot_be_applied_to_non_override_pages(): void
    {
        $publisher = app(PageBuilderPublisher::class);
        $page = $this->cmsPage('/only-static');
        $publisher->publish($page);

        $this->expectException(ValidationException::class);
        $publisher->setOverrideEnabled($page, true);
    }

    public function test_preview_remains_available_for_unpublished_override(): void
    {
        $page = $this->cmsPage('/events');
        $this->assertNull($page->published_at);
        $this->assertTrue(app(PageBuilderRouteRegistry::class)->inspect('/events')['eligible']);
        $this->withoutVite();

        $this->get('/events')->assertOk()->assertDontSee('CMS EVENTS VERSION');
    }
}
