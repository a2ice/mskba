<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\PageBuilderMarkup;
use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Application\Services\PageBuilderRenderer;
use App\Modules\Content\Application\Services\PageBuilderScriptVersionStore;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PageBuilderNestedComponentsTest extends TestCase
{
    use RefreshDatabase;

    private function component(string $slug, string $html, string $css = ''): PageBuilderComponent
    {
        $component = PageBuilderComponent::query()->create([
            'slug' => $slug,
            'name' => $slug,
            'draft_html' => $html,
            'draft_css' => $css,
        ]);
        app(PageBuilderPublisher::class)->publish($component);

        return $component->refresh();
    }

    private function page(string $html): PageBuilderPage
    {
        $page = PageBuilderPage::query()->where('path', '/')->firstOrFail();
        $page->update(['draft_html' => $html]);
        app(PageBuilderPublisher::class)->publish($page);

        return $page->refresh();
    }

    public function test_nested_components_render_markup_css_and_published_scripts_through_three_levels(): void
    {
        $kit = $this->component(
            'home-venues-section-form-workshop',
            '<div class="mskba-kit-preview" role="img" aria-label="Макет">'
                .'<button type="button" class="kit-switch" data-tab-name="front" aria-pressed="true">Лицевая</button>'
                .'</div>',
            '.mskba-kit-preview { color: orange; }',
        );
        $form = $this->component(
            'home-venues-section-form',
            '<div class="tabs-wrapper"><button type="button" class="tab-switcher" data-tab-name="store" aria-pressed="true">Готовая форма</button>'
                .'<mskba-component name="home-venues-section-form-workshop"></mskba-component></div>',
            '.tabs-wrapper { display: grid; }',
        );
        $arena = $this->component(
            'home-venues-arena',
            '<section class="mskba-v3-story__section--uniforms"><div class="mskba-v3-story__body-content">'
                .'<mskba-component name="home-venues-section-form"></mskba-component>'
                .'</div></section>',
            '.mskba-v3-story__body-content { max-width: 500px; }',
        );
        $page = $this->page('<mskba-component name="home-venues-arena"></mskba-component>');
        $admin = User::factory()->create(['system_role' => UserSystemRoleEnum::SUPERADMIN]);
        $store = app(PageBuilderScriptVersionStore::class);
        foreach ([$arena, $form, $kit] as $component) {
            $store->saveDraft($admin, $component, 'console.log("'.$component->slug.'");');
            $store->publish($admin, $component);
            $store->setEnabled($admin, $component, true);
        }

        $output = app(PageBuilderRenderer::class)->render($page);
        $this->assertStringContainsString('class="tabs-wrapper"', $output['html']);
        $this->assertStringContainsString('class="mskba-kit-preview"', $output['html']);
        $this->assertStringContainsString('data-tab-name="store"', $output['html']);
        $this->assertStringContainsString('data-tab-name="front"', $output['html']);
        $this->assertStringContainsString('aria-pressed="true"', $output['html']);
        $this->assertStringNotContainsString('<mskba-component', $output['html']);
        foreach (['.tabs-wrapper', '.mskba-kit-preview', '.mskba-v3-story__body-content'] as $selector) {
            $this->assertStringContainsString($selector, $output['css']);
        }
        $this->assertCount(3, $output['scripts']);
        $this->assertSame([], app(PageBuilderRenderer::class)->render($page, true)['scripts']);
    }

    public function test_unpublished_nested_components_do_not_leak_drafts_into_public_html(): void
    {
        PageBuilderComponent::query()->create([
            'slug' => 'private-draft',
            'name' => 'Черновик',
            'draft_html' => '<div class="secret-draft">Не опубликовано</div>',
        ]);
        $parent = $this->component(
            'published-parent',
            '<p>Публичная секция</p><mskba-component name="private-draft"></mskba-component>',
        );
        $page = $this->page('<mskba-component name="'.$parent->slug.'"></mskba-component>');

        $public = app(PageBuilderRenderer::class)->render($page);
        $this->assertStringContainsString('Публичная секция', $public['html']);
        $this->assertStringNotContainsString('Не опубликовано', $public['html']);
        $this->assertStringNotContainsString('Неизвестный', $public['html']);

        $preview = app(PageBuilderRenderer::class)->render($page, true);
        $this->assertStringContainsString('Не опубликовано', $preview['html']);
    }

    public function test_recursive_component_cycles_fail_closed_without_breaking_the_arena(): void
    {
        $this->component('section-alpha', '<div>Alpha<mskba-component name="section-beta"></mskba-component></div>');
        $this->component('section-beta', '<div>Beta<mskba-component name="section-alpha"></mskba-component></div>');
        $page = $this->page('<mskba-component name="section-alpha"></mskba-component>');

        $html = app(PageBuilderRenderer::class)->render($page)['html'];
        $this->assertStringContainsString('Alpha', $html);
        $this->assertStringContainsString('Beta', $html);
        $this->assertStringNotContainsString('mskba-component', $html);
        $this->assertLessThan(1000, strlen($html));

        $preview = app(PageBuilderRenderer::class)->render($page, true)['html'];
        $this->assertStringContainsString('Циклическая', $preview);
    }

    public function test_button_sanitizer_preserves_safe_tab_controls_without_executable_attributes(): void
    {
        $html = app(PageBuilderMarkup::class)->html(
            '<button class="tab-switcher" type="submit" data-tab-name="store" aria-pressed="true"'
                .' onclick="alert(1)">Готовая форма</button>',
        );
        $this->assertStringContainsString('<button', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('data-tab-name="store"', $html);
        $this->assertStringContainsString('aria-pressed="true"', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('type="submit"', $html);
    }
}
