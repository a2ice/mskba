<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\PageBuilderMarkup;
use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Application\Services\PageBuilderRenderer;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderRevision;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PageBuilderFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function home(): PageBuilderPage
    {
        return PageBuilderPage::query()->where('path', '/')->firstOrFail();
    }

    public function test_migration_keeps_current_home_page_until_explicit_publish(): void
    {
        $page = $this->home();

        $this->assertNull($page->published_at);
        $this->assertNull(app(\App\Modules\Content\Presentation\Http\PageBuilderController::class)
            ->home(app(PageBuilderRenderer::class)));
        $this->assertSame('base', $page->template?->slug);
        $this->assertNotNull($page->template?->published_at);
    }

    public function test_published_page_has_separate_snapshot_and_registered_components(): void
    {
        $component = PageBuilderComponent::query()->create([
            'slug' => 'hero-banner', 'name' => 'Баннер',
            'draft_html' => '<section class="hero"><h2>Заголовок</h2></section>',
            'draft_css' => '.hero { color: var(--accent); }',
        ]);
        $publisher = app(PageBuilderPublisher::class);
        $publisher->publish($component);
        $page = $this->home();
        $page->update([
            'draft_html' => '<mskba-component name="hero-banner"></mskba-component>'
                .'<mskba-component name="games-list" source="upcoming_games" limit="3"></mskba-component>',
            'draft_css' => '.home-intro { padding: 24px; }',
        ]);
        $publisher->publish($page);
        $this->assertNotNull($page->refresh()->published_at);

        $html = app(PageBuilderRenderer::class)->render($page);
        $this->assertStringContainsString('Заголовок', $html['html']);
        $this->assertStringContainsString('data-mskba-builder-source="upcoming_games"', $html['html']);
        $this->assertStringContainsString('.mskba-page-builder .hero', $html['css']);
        $this->assertStringContainsString('.mskba-page-builder .home-intro', $html['css']);

        $page->update(['draft_html' => '<h1>Новый черновик</h1>']);
        $this->assertStringNotContainsString('Новый черновик', app(PageBuilderRenderer::class)->render($page)['html']);
        $this->assertStringContainsString('Новый черновик', app(PageBuilderRenderer::class)->render($page, true)['html']);
        $this->assertSame(1, PageBuilderRevision::query()->where('entity_type', 'page')->count());
        $revision = PageBuilderRevision::query()->where('entity_type', 'page')->firstOrFail();
        $publisher->restoreDraft($page, $revision->id);
        $this->assertStringContainsString('games-list', $page->refresh()->draft_html);
    }

    public function test_css_and_html_code_cannot_execute_scripts_or_global_styles(): void
    {
        $markup = app(PageBuilderMarkup::class);
        foreach (['<script>alert(1)</script>', '<div onclick="alert(1)">X</div>',
            '{{ file_get_contents("/etc/passwd") }}', '<iframe src="https://example.org"></iframe>'] as $html) {
            try {
                $markup->validate($html, null);
                $this->fail('Unsafe HTML accepted: '.$html);
            } catch (ValidationException) {
                // expected
            }
        }
        foreach (['body { color: red; }', '.box { background: url(https://evil.org/x); }',
            '@import "https://evil.org/a";'] as $css) {
            try {
                $markup->css($css);
                $this->fail('Unsafe CSS accepted: '.$css);
            } catch (ValidationException) {
                // expected
            }
        }
        $this->assertStringContainsString('.mskba-page-builder .hero',
            $markup->css('.hero { background-color: #ff0000; }'));
    }

    public function test_preview_requires_backoffice_rights_and_does_not_publish(): void
    {
        $page = $this->home();
        $this->get(route('page-builder.preview', $page))->assertRedirect();
        $user = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $this->withoutVite();
        $this->actingAs($user)->get(route('page-builder.preview', $page))
            ->assertOk()->assertSee('Предпросмотр черновика')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertNull($page->refresh()->published_at);
    }

    public function test_public_sources_are_bounded_and_private_source_requires_auth(): void
    {
        $this->getJson(route('page-builder.data', 'upcoming_games').'?limit=999')->assertOk()
            ->assertJsonStructure(['items']);
        $this->getJson(route('page-builder.data', 'my_games'))->assertUnauthorized();
        $this->getJson(route('page-builder.data', 'unknown'))->assertNotFound();
    }

    public function test_superadmin_can_open_all_builder_backoffice_resource_screens(): void
    {
        $user = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $this->withoutVite();
        $this->actingAs($user);

        \Filament\Facades\Filament::setCurrentPanel(
            \Filament\Facades\Filament::getPanel('backoffice')
        );

        foreach ([
            \App\Filament\Backoffice\Resources\PageBuilder\BuilderPageResource::class,
            \App\Filament\Backoffice\Resources\PageBuilder\BuilderTemplateResource::class,
            \App\Filament\Backoffice\Resources\PageBuilder\BuilderComponentResource::class,
        ] as $resource) {
            $this->get($resource::getUrl('index'))->assertOk();
            $this->get($resource::getUrl('create'))->assertOk();
        }
        $this->get(\App\Filament\Backoffice\Resources\PageBuilder\BuilderPageResource::getUrl(
            'edit', ['record' => $this->home()->id]
        ))->assertOk();
        $this->get(\App\Filament\Backoffice\Resources\PageBuilder\BuilderTemplateResource::getUrl(
            'edit', ['record' => $this->home()->template_id]
        ))->assertOk();
        $this->get(\App\Filament\Backoffice\Resources\PageBuilder\BuilderComponentResource::getUrl(
            'edit', ['record' => PageBuilderComponent::query()->where('slug', 'hero-slider')->firstOrFail()->id]
        ))->assertOk();
    }

    public function test_custom_public_page_never_shadows_existing_laravel_routes(): void
    {
        $page = PageBuilderPage::query()->create([
            'path' => '/about-mskba', 'name' => 'О проекте',
            'draft_html' => '<h1>Новый раздел</h1>',
        ]);
        app(PageBuilderPublisher::class)->publish($page);
        $this->withoutVite();

        $this->get('/about-mskba')->assertOk()->assertSee('Новый раздел');
        $this->get('/faq')->assertOk();
        $this->get('/missing/non-existent')->assertNotFound();
    }

    public function test_published_url_is_unchanged_when_only_draft_path_changes(): void
    {
        $publisher = app(PageBuilderPublisher::class);
        $page = PageBuilderPage::query()->create([
            'name' => 'Демонстрация', 'path' => '/builder-demo',
            'draft_html' => '<h1>Тестовая страница</h1>',
        ]);
        $publisher->publish($page);
        $this->withoutVite();

        $this->assertSame('/builder-demo', $page->refresh()->published_path);
        $this->get('/builder-demo')->assertOk()->assertSee('Тестовая страница')
            ->assertSee('rel="canonical"', false);
        $page->update(['path' => '/builder-next', 'draft_html' => '<h1>Новый черновик</h1>']);

        $this->get('/builder-demo')->assertOk()->assertSee('Тестовая страница')
            ->assertDontSee('Новый черновик');
        $this->get('/builder-next')->assertNotFound();
        $this->assertSame('/builder-demo', $page->refresh()->published_path);

        $publisher->publish($page);
        $this->assertSame('/builder-next', $page->refresh()->published_path);
        $this->get('/builder-demo')->assertNotFound();
        $this->get('/builder-next')->assertOk()->assertSee('Новый черновик');
        $this->assertSame('/builder-next', PageBuilderRevision::query()
            ->where('entity_type', 'page')->latest('id')->firstOrFail()->snapshot['path']);
    }

    public function test_published_url_cannot_be_taken_by_another_page(): void
    {
        $publisher = app(PageBuilderPublisher::class);
        $first = PageBuilderPage::query()->create([
            'name' => 'Первая', 'path' => '/first-public',
            'draft_html' => '<h1>Первая</h1>',
        ]);
        $publisher->publish($first);
        $first->update(['path' => '/first-draft']);

        $second = PageBuilderPage::query()->create([
            'name' => 'Вторая', 'path' => '/first-public',
            'draft_html' => '<h1>Вторая</h1>',
        ]);
        $this->expectException(ValidationException::class);
        $publisher->publish($second);
    }

    public function test_reserved_path_is_not_publishable(): void
    {
        $page = PageBuilderPage::query()->create([
            'name' => 'Системный адрес', 'path' => '/account', 'draft_html' => '<h1>X</h1>',
        ]);
        $this->expectException(ValidationException::class);
        app(PageBuilderPublisher::class)->publish($page);
    }
}
