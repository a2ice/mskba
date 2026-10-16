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

    public function test_page_builder_is_a_fluid_canvas_with_explicit_boxed_and_full_width_sections(): void
    {
        $seed = $this->home();
        $this->assertStringStartsWith('<div class="container">', $seed->draft_html);
        $this->assertStringNotContainsString('1320px', file_get_contents(
            resource_path('css/page-builder.css'),
        ));
        $this->assertStringContainsString('.mskba-page-builder{width:100%', file_get_contents(
            resource_path('css/page-builder.css'),
        ));
        // The CMS canvas and base-template section must not impose spacing:
        // a full-width hero can start flush against the header/context bar.
        $css = file_get_contents(resource_path('css/page-builder.css'));
        preg_match('/\\.mskba-page-builder\\s*\\{([^}]+)\\}/', $css, $root);
        $this->assertNotEmpty($root);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:padding|margin|gap|max-width)(?:-[_a-z]+)?\\s*:/', $root[1]);
        preg_match('/\\.mskba-builder-section\\s*\\{([^}]+)\\}/', $css, $section);
        $this->assertNotEmpty($section);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:padding|margin|gap)(?:-[_a-z]+)?\\s*:/', $section[1]);

        $html = '<section class="wide-section"><h1>Вся ширина</h1></section>'
            .'<div class="container"><h2>По общей сетке шапки</h2></div>';
        $seed->update(['draft_html' => $html]);
        $publisher = app(PageBuilderPublisher::class);
        $publisher->publish($seed);

        $rendered = app(PageBuilderRenderer::class)->render($seed->refresh())['html'];
        $this->assertStringContainsString('<section class="wide-section">', $rendered);
        $this->assertStringContainsString('<div class="container">', $rendered);
        $this->assertStringContainsString('Вся ширина', $rendered);
        $this->assertStringContainsString('По общей сетке шапки', $rendered);
    }


    public function test_page_builder_spacing_utilities_are_scoped_and_preserve_html_classes(): void
    {
        $css = file_get_contents(resource_path('css/page-builder-spacing.css'));
        $this->assertIsString($css);
        $sizes = ['0', '.25rem', '.5rem', '1rem', '1.5rem', '3rem'];
        $groups = [
            'm' => 'margin', 'mt' => 'margin-top', 'mb' => 'margin-bottom',
            'ms' => 'margin-inline-start', 'me' => 'margin-inline-end',
            'ml' => 'margin-left', 'mr' => 'margin-right',
            'mx' => 'margin-inline', 'my' => 'margin-block',
            'p' => 'padding', 'pt' => 'padding-top', 'pb' => 'padding-bottom',
            'ps' => 'padding-inline-start', 'pe' => 'padding-inline-end',
            'pl' => 'padding-left', 'pr' => 'padding-right',
            'px' => 'padding-inline', 'py' => 'padding-block', 'gap' => 'gap',
        ];
        foreach ($groups as $prefix => $property) {
            foreach ($sizes as $index => $size) {
                $this->assertStringContainsString(
                    '.mskba-page-builder .'.$prefix.'-'.$index.'{'.$property.':'.$size.'}',
                    $css,
                );
            }
        }
        $this->assertStringContainsString('.mskba-page-builder .mx-auto{margin-inline:auto}', $css);
        $this->assertStringContainsString(
            "@import './page-builder-spacing.css';",
            file_get_contents(resource_path('css/page-builder.css')),
        );

        $html = app(PageBuilderMarkup::class)->html(
            '<section class="py-5 px-3"><div class="container mt-2 mb-4">Текст</div></section>',
        );
        $this->assertStringContainsString('class="py-5 px-3"', $html);
        $this->assertStringContainsString('class="container mt-2 mb-4"', $html);
    }

    public function test_legacy_boxed_migration_is_idempotent_and_preserves_existing_containers(): void
    {
        $legacy = PageBuilderPage::query()->create([
            'name' => 'До обновления', 'path' => '/legacy',
            'draft_html' => '<h1>Раньше было ограничено</h1>',
        ]);
        $legacy->forceFill([
            'published_html' => '<h1>Публикация</h1>',
            'published_path' => '/legacy',
            'published_at' => now(),
        ])->save();
        $already = PageBuilderPage::query()->create([
            'name' => 'Уже с контейнером', 'path' => '/already-boxed',
            'draft_html' => '<section class="container"><h2>Ок</h2></section>',
        ]);

        $migration = require database_path('migrations/2026_10_16_183000_preserve_existing_page_builder_boxed_sections.php');
        $migration->up();
        $migration->up();

        $this->assertSame(
            '<div class="container"><h1>Раньше было ограничено</h1></div>',
            $legacy->refresh()->draft_html,
        );
        $this->assertSame(
            '<div class="container"><h1>Публикация</h1></div>',
            $legacy->published_html,
        );
        $this->assertSame(
            '<section class="container"><h2>Ок</h2></section>',
            $already->refresh()->draft_html,
        );
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
