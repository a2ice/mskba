<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PageBuilderVenuesSceneTest extends TestCase
{
    use RefreshDatabase;

    public function test_venues_scene_renders_four_trusted_layers_and_accessible_content(): void
    {
        $art = view('page-builder.components.venues-scene-art')->render();
        $scene = view('page-builder.components.venues-scene')->render();

        foreach ([
            'layer-1-backstage.png',
            'layer-2-fense.png',
            'layer-3-hoop.png',
            'layer-4-player.png',
        ] as $asset) {
            $this->assertStringContainsString('/images/court_day/homepage/'.$asset, $art);
        }

        $this->assertSame(4, substr_count($art, 'mskba-home-story__art-layer--'));
        $this->assertStringContainsString('data-mskba-venues-scene', $scene);
        $this->assertStringContainsString('data-mskba-ball-target', $scene);
        $this->assertLessThan(
            strpos($scene, 'mskba-venues-scene__inner'),
            strpos($scene, 'mskba-venues-scene__ghost'),
            'decorative title must be a full-width section child before the boxed shell',
        );
        $this->assertStringContainsString('mskba-venues-scene__ghost', $scene);
        $this->assertStringContainsString('aria-labelledby="mskba-venues-scene-title"', $scene);
        $this->assertStringContainsString('Найти площадку', $scene);
        $this->assertStringContainsString('Бронирование', $scene);
        $this->assertStringContainsString('Фильтры', $scene);
        $this->assertSame(5, substr_count($scene, 'href="/venues"'),
            'one CTA and four navigation highlights must remain functional');
    }

    public function test_venues_scene_appears_only_on_staging_published_homepage(): void
    {
        $this->withoutVite();
        $slider = PageBuilderComponent::query()->where('slug', 'hero-slider')->firstOrFail();
        $publisher = app(PageBuilderPublisher::class);
        $publisher->publish($slider);

        $home = PageBuilderPage::query()->where('path', '/')->firstOrFail();
        $home->update([
            'draft_html' => '<section id="hero" class="bg-lightgrey">'
                .'<div class="mskba-hero-scene">'
                .'<mskba-component name="hero-slider"></mskba-component>'
                .'</div></section>',
        ]);
        $publisher->publish($home);

        $this->get('/')->assertOk()
            ->assertSee('data-mskba-hero-slider', false)
            ->assertDontSee('data-mskba-venues-scene', false);

        app()->instance('env', 'staging');
        $this->get('/')->assertOk()
            ->assertSee('data-mskba-home-story', false)
            ->assertSee('data-mskba-venues-scene', false)
            ->assertSee('layer-4-player.png', false);

        // A published homepage with no Hero must never acquire the scenery.
        $home->update(['draft_html' => '<section><h1>Новости MSKBA</h1></section>']);
        $publisher->publish($home);
        $this->get('/')->assertOk()
            ->assertDontSee('data-mskba-home-story', false)
            ->assertDontSee('data-mskba-venues-scene', false);
    }

    public function test_scene_css_uses_a_document_height_owner_and_responsive_layouts(): void
    {
        $imports = file_get_contents(resource_path('css/page-builder.css'));
        $css = file_get_contents(resource_path('css/page-builder-venues-scene.css'));

        $this->assertStringContainsString("@import './page-builder-venues-scene.css';", $imports);
        $this->assertStringContainsString('.mskba-page-builder .mskba-home-story', $css);
        $this->assertStringContainsString('position: relative;', $css);
        $this->assertStringContainsString('.mskba-home-story__art', $css);
        $this->assertStringContainsString('position: absolute;', $css);
        $this->assertStringContainsString('@media (max-width: 1050px)', $css);
        $this->assertStringContainsString('@media (max-width: 640px)', $css);
        $this->assertStringContainsString('--venues-foreground-width: 74%;', $css,
            'artwork needs an explicit authored scale independent of asset dimensions');
        $this->assertStringContainsString('--venues-foreground-top:', $css);
        $this->assertStringContainsString('object-fit: contain;', $css,
            'transparent foregrounds must never use cover against section height');
        $this->assertStringContainsString('grid-template-columns: repeat(4, minmax(0, 1fr))', $css);
        $this->assertStringContainsString('max-width: 100%;', $css);
    }
}
