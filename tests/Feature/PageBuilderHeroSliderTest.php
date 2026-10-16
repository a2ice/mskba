<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\HeroSliderSettings;
use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Application\Services\PageBuilderRenderer;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PageBuilderHeroSliderTest extends TestCase
{
    use RefreshDatabase;

    private function slider(): PageBuilderComponent
    {
        return PageBuilderComponent::query()->where('slug', 'hero-slider')->firstOrFail();
    }

    private function home(): PageBuilderPage
    {
        return PageBuilderPage::query()->where('path', '/')->firstOrFail();
    }

    public function test_migration_creates_unpublished_editable_hero_component(): void
    {
        $slider = $this->slider();
        $this->assertNull($slider->published_at);
        $this->assertCount(2, $slider->draft_slides);
        $this->assertNull($slider->published_slides);
        $this->assertNull($this->home()->published_at);
    }

    public function test_page_preview_renders_draft_without_publishing_and_public_requires_publish(): void
    {
        $home = $this->home();
        $home->update(['draft_html' => '<mskba-component name="hero-slider"></mskba-component>']);
        $renderer = app(PageBuilderRenderer::class);
        $preview = $renderer->render($home, true)['html'];

        $this->assertStringContainsString('data-mskba-hero-slider', $preview);
        $this->assertStringContainsString('БУДЬ В КОМЬЮНИТИ.', $preview);
        $this->assertStringContainsString('data-hero-go="1"', $preview);
        $this->assertStringContainsString('mskba-hero-slide__highlights', $preview);
        $this->assertStringNotContainsString('<script', $preview);

        // A published page cannot show a draft-only component.
        $publisher = app(PageBuilderPublisher::class);
        $publisher->publish($home);
        $this->assertStringNotContainsString('data-mskba-hero-slider', $renderer->render($home)['html']);
        $publisher->publish($this->slider());
        $this->assertStringContainsString('data-mskba-hero-slider', $renderer->render($home)['html']);
    }

    public function test_slide_content_is_escaped_and_draft_does_not_mutate_published_snapshot(): void
    {
        $publisher = app(PageBuilderPublisher::class);
        $slider = $this->slider();
        $publisher->publish($slider);
        $home = $this->home();
        $home->update(['draft_html' => '<mskba-component name="hero-slider"></mskba-component>']);
        $publisher->publish($home);

        $slides = $slider->draft_slides;
        $slides[0]['title'] = '<img src=x onerror=alert(1)>';
        $slides[0]['description'] = 'Новый черновик';
        $slider->update(['draft_slides' => $slides]);

        $renderer = app(PageBuilderRenderer::class);
        $published = $renderer->render($home)['html'];
        $preview = $renderer->render($home, true)['html'];
        $this->assertStringContainsString('ИГРАЙ.', $published);
        $this->assertStringNotContainsString('Новый черновик', $published);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $preview);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $preview);
    }

    public function test_malicious_links_media_and_oversized_slide_lists_are_rejected(): void
    {
        $slides = $this->slider()->draft_slides;
        foreach ([
            ['cta_url', 'javascript:alert(1)'],
            ['background_desktop', 'https://untrusted.example/track.svg'],
            ['near_player', '../../private/image.png'],
            ['animation_near', 'arbitrary-javascript'],
        ] as [$field, $bad]) {
            $candidate = $slides;
            $candidate[0][$field] = $bad;
            try {
                HeroSliderSettings::validate($candidate, []);
                $this->fail('Unsafe slide field was accepted: '.$field);
            } catch (ValidationException) {
                // Expected. No stored editor value is treated as script or URL.
            }
        }
        $this->expectException(ValidationException::class);
        HeroSliderSettings::validate(array_fill(0, 7, $slides[0]), []);
    }

    public function test_published_slides_are_versioned_and_restoration_changes_only_draft(): void
    {
        $slider = $this->slider();
        $publisher = app(PageBuilderPublisher::class);
        $publisher->publish($slider);
        $oldTitle = $slider->published_slides[0]['title'];
        $revision = PageBuilderRevision::query()
            ->where('entity_type', 'component')->where('entity_id', $slider->id)->firstOrFail();
        $slides = $slider->draft_slides;
        $slides[0]['title'] = 'НОВЫЙ ЧЕРНОВИК';
        $slider->update(['draft_slides' => $slides]);
        $publisher->restoreDraft($slider, $revision->id);

        $this->assertSame($oldTitle, $slider->refresh()->draft_slides[0]['title']);
        $this->assertSame($oldTitle, $slider->published_slides[0]['title']);
        $this->assertSame($oldTitle, $revision->snapshot['draft_slides'][0]['title']);
    }

    public function test_player_assets_and_internal_links_are_validated(): void
    {
        $slides = $this->slider()->draft_slides;
        $slides[0]['near_player'] = 'page-builder/hero/abc123.webp';
        $slides[0]['far_player'] = 'page-builder/hero/def456.png';
        $slides[0]['background_mobile'] = 'page-builder/hero/mobile.webp';
        $slides[0]['cta_url'] = '#about';
        HeroSliderSettings::validate($slides, ['interval_ms' => 7000]);

        $this->assertTrue(true);
    }
}
