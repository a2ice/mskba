<?php

namespace Tests\Feature;

use Tests\TestCase;

final class PageBuilderVenuesSceneTest extends TestCase
{
    public function test_venues_scene_is_a_trusted_standalone_view_with_four_original_art_layers(): void
    {
        $html = view('page-builder.components.venues-scene')->render();

        $this->assertStringContainsString('data-mskba-venues-scene', $html);
        $this->assertStringContainsString('id="mskba-venues-title"', $html);
        $this->assertStringContainsString('href="/venues"', $html);
        $this->assertStringContainsString('aria-label="Возможности поиска площадок"', $html);
        $this->assertSame(4, substr_count($html, 'mskba-venues-art__layer--'));

        foreach ([
            'layer-1-backstage.png',
            'layer-2-fense.png',
            'layer-3-hoop.png',
            'layer-4-player.png',
        ] as $image) {
            $this->assertStringContainsString('/images/court_day/homepage/'.$image, $html);
            $this->assertFileExists(public_path('images/court_day/homepage/'.$image));
        }
    }

    public function test_staging_venues_scene_has_responsive_css_and_no_new_animated_scroll_handler(): void
    {
        $stylesheet = file_get_contents(resource_path('css/page-builder-venues-scene.css'));
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('.mskba-home-story', $stylesheet);
        $this->assertStringContainsString('position: absolute', $stylesheet);
        $this->assertStringContainsString('@media (min-width: 641px) and (max-width: 1050px)', $stylesheet);
        $this->assertStringContainsString('@media (max-width: 640px)', $stylesheet);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $stylesheet);
        $this->assertStringNotContainsString('position: fixed', $stylesheet);
    }
}
