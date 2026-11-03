<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\PageBuilderComponentTags;
use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Application\Services\PageBuilderRenderer;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PageBuilderHeroOverlaySlotTest extends TestCase
{
    use RefreshDatabase;

    private function hero(): PageBuilderComponent
    {
        return PageBuilderComponent::query()->where('slug', 'hero-slider')->firstOrFail();
    }

    private function home(): PageBuilderPage
    {
        return PageBuilderPage::query()->where('path', '/')->firstOrFail();
    }

    public function test_author_html_inside_hero_is_sanitized_rendered_inside_stage_and_published(): void
    {
        $page = $this->home();
        $markup = <<<'HTML'
<div class="mskba-hero-scene">
  <mskba-component name="hero-slider">
    <div class="mskba-rolling-ball">
      <img class="mskba-rolling-ball__sprite" src="/images/ball-rolling.png" alt="">
    </div>
  </mskba-component>
</div>
HTML;
        $page->update(['draft_html' => $markup]);
        $preview = app(PageBuilderRenderer::class)->render($page, true);
        $html = $preview['html'];

        $this->assertStringContainsString('data-hero-overlay', $html);
        $this->assertStringContainsString('class="mskba-rolling-ball"', $html);
        $this->assertStringContainsString('src="/images/ball-rolling.png"', $html);
        $this->assertStringNotContainsString('<mskba-component', $html);
        $this->assertLessThan(
            strpos($html, 'data-hero-overlay'),
            strpos($html, 'mskba-hero-slider__stage'),
            'The overlay belongs inside the Hero stage.',
        );
        $this->assertStringContainsString('.mskba-hero-slider__overlay', $preview['css']);

        $publisher = app(PageBuilderPublisher::class);
        $publisher->publish($this->hero());
        $publisher->publish($page);
        $public = app(PageBuilderRenderer::class)->render($page->refresh());
        $this->assertStringContainsString('data-hero-overlay', $public['html']);
        $this->assertStringContainsString('mskba-rolling-ball__sprite', $public['html']);
        $this->assertStringContainsString('z-index:3', $public['css']);
    }

    public function test_existing_empty_hero_tag_remains_supported(): void
    {
        $page = $this->home();
        $page->update(['draft_html' => '<mskba-component name="hero-slider"></mskba-component>']);
        $html = app(PageBuilderRenderer::class)->render($page, true)['html'];
        $this->assertStringContainsString('data-mskba-hero-slider', $html);
        $this->assertStringNotContainsString('data-hero-overlay', $html);
    }

    public function test_non_hero_components_reject_nested_html_instead_of_losing_it(): void
    {
        $bad = '<mskba-component name="games-list" source="upcoming_games"><div>Text</div></mskba-component>';
        $page = $this->home();
        $page->update(['draft_html' => $bad]);

        try {
            app(PageBuilderRenderer::class)->render($page, true);
            $this->fail('Unsupported nested HTML must not disappear in preview.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('draft_html', $e->errors());
        }
        $this->expectException(ValidationException::class);
        app(PageBuilderPublisher::class)->publish($page);
    }

    public function test_unclosed_and_nested_components_are_rejected(): void
    {
        foreach ([
            '<mskba-component name="hero-slider"><div>unclosed</div>',
            '<mskba-component name="hero-slider"><mskba-component name="hero-slider"></mskba-component></mskba-component>',
        ] as $html) {
            try {
                PageBuilderComponentTags::find($html);
                $this->fail('Invalid component grammar must be rejected.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('draft_html', $e->errors());
            }
        }
    }

    public function test_unsafe_inline_html_is_not_allowed_and_untrusted_attributes_are_sanitized(): void
    {
        $page = $this->home();
        $page->update(['draft_html' => '<mskba-component name="hero-slider"><img src="/images/ball-rolling.png" onerror="alert(1)"></mskba-component>']);
        $this->expectException(ValidationException::class);
        app(PageBuilderRenderer::class)->render($page, true);
    }

    public function test_overlay_css_migration_preserves_editor_changes_and_is_idempotent(): void
    {
        $slider = $this->hero();
        app(PageBuilderPublisher::class)->publish($slider);
        // Published and previous CSS are guarded: test fixture must use
        // forceFill rather than Eloquent's public mass-assignment API.
        $slider->forceFill([
            'draft_css' => '.authored-draft { color: red; }',
            'published_css' => '.authored-published { color: blue; }',
            'previous_css' => '.authored-previous { color: green; }',
        ])->save();

        $migration = require database_path('migrations/2026_11_04_001500_add_page_builder_hero_overlay_slot_css.php');
        $migration->up();
        $migration->up();
        $slider->refresh();

        foreach (['draft_css', 'published_css', 'previous_css'] as $field) {
            $this->assertSame(1, substr_count((string) $slider->$field, 'MSKBA_HERO_OVERLAY_SLOT_20261104'));
        }
        $this->assertStringContainsString('.authored-draft', $slider->draft_css);
        $this->assertStringContainsString('.authored-published', $slider->published_css);
        $this->assertStringContainsString('.authored-previous', $slider->previous_css);
    }
}
