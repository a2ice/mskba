<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\HeroSliderSettings;
use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Application\Services\PageBuilderRenderer;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderRevision;
use App\Filament\Backoffice\Resources\PageBuilder\BuilderComponentResource;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
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

    public function test_superadmin_can_open_seeded_hero_filament_editor(): void
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

        $this->get(BuilderComponentResource::getUrl('edit', [
            'record' => $this->slider()->id,
        ]))->assertOk()->assertSee('Hero Slider');
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

        // Never publish a blank homepage while its hero component is still a draft.
        $publisher = app(PageBuilderPublisher::class);
        try {
            $publisher->publish($home);
            $this->fail('Draft-only Hero Slider should block publication.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('hero-slider', $exception->errors()['draft_html'][0]);
        }
        $this->assertNull($home->refresh()->published_at);
        $this->assertSame(0, PageBuilderRevision::query()->where('entity_type', 'page')->count());

        $publisher->publish($this->slider());
        $publisher->publish($home);
        $this->assertStringContainsString('data-mskba-hero-slider', $renderer->render($home->refresh())['html']);
    }


    public function test_existing_public_page_is_not_modified_when_draft_component_is_unpublished(): void
    {
        $publisher = app(PageBuilderPublisher::class);
        $page = $this->home();
        $page->update(['draft_html' => '<h1>Первая публикация</h1>']);
        $publisher->publish($page);
        $page->update(['draft_html' => '<mskba-component name="hero-slider"></mskba-component>']);

        try {
            $publisher->publish($page);
            $this->fail('Publish must fail for an unpublished hero.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('hero-slider', $exception->errors()['draft_html'][0]);
        }
        $page->refresh();
        $this->assertSame('<h1>Первая публикация</h1>', $page->published_html);
        $this->assertSame(1, PageBuilderRevision::query()->where('entity_type', 'page')->count());
    }

    public function test_ordinary_unpublished_component_and_invalid_source_block_publish(): void
    {
        $page = $this->home();
        $page->update(['draft_html' => '<mskba-component name="not-published"></mskba-component>']);
        $publisher = app(PageBuilderPublisher::class);
        try {
            $publisher->publish($page);
            $this->fail('Missing generic component should block publication.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('not-published', $exception->errors()['draft_html'][0]);
        }

        $page->update(['draft_html' => '<mskba-component name="games-list" source="not_allowed"></mskba-component>']);
        try {
            $publisher->publish($page);
            $this->fail('Unregistered dynamic source should block publication.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('games-list', $exception->errors()['draft_html'][0]);
        }

        $page->update(['draft_html' => '<mskba-component name="games-list" source="upcoming_games"></mskba-component>']);
        $publisher->publish($page);
        $this->assertNotNull($page->refresh()->published_at);
    }

    public function test_slide_content_is_escaped_and_draft_does_not_mutate_published_snapshot(): void
    {
        $publisher = app(PageBuilderPublisher::class);
        $slider = $this->slider();
        $publisher->publish($slider);
        $home = $this->home();
        $home->update(['draft_html' => '<mskba-component name="hero-slider"></mskba-component>']);
        $publisher->publish($home);
        $home->refresh();

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
        $oldTitle = $slider->refresh()->published_slides[0]['title'];
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

    public function test_layer_animation_presets_and_stagger_delays_are_bounded(): void
    {
        $slides = $this->slider()->draft_slides;
        $slides[0]['animation_background'] = 'fade';
        $slides[0]['animation_cta'] = 'slide-up';
        $slides[0]['animation_highlights'] = 'zoom';
        $slides[0]['delay_far_ms'] = 2300;
        HeroSliderSettings::validate($slides, ['autoplay' => false]);

        $slides[0]['delay_far_ms'] = 8000;
        $this->expectException(ValidationException::class);
        HeroSliderSettings::validate($slides, []);
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
