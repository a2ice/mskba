<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\PageBuilderMarkup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenuesMapExpandToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_reveal_is_bundled_as_theme_enhancement_not_a_filament_publication(): void
    {
        $js = file_get_contents(resource_path('themes/mskba_app/js/features/home-venues-map-expand.js'));
        $css = file_get_contents(resource_path('themes/mskba_app/css/home-venues-map-expand.css'));
        $appJs = file_get_contents(resource_path('themes/mskba_app/js/app.js'));
        $appCss = file_get_contents(resource_path('themes/mskba_app/css/app.css'));
        $mapHtml = file_get_contents(resource_path('page-builder/venues-map.html'));
        $mapJs = file_get_contents(resource_path('page-builder/venues-map.js'));
        $mapCss = file_get_contents(resource_path('page-builder/venues-map.css'));
        $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));

        $this->assertStringContainsString("import './features/home-venues-map-expand.js'", $appJs);
        $this->assertStringContainsString("@import './home-venues-map-expand.css'", $appCss);
        $this->assertStringContainsString('is-map-expanded', $js);
        $this->assertStringContainsString('copy.inert = expanded', $js);
        $this->assertStringContainsString("if (!ready) return;", $js);
        $this->assertStringContainsString('mskba-venues-map__collapse', $css);
        $this->assertStringContainsString('translateX(-100%)', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);

        $this->assertStringContainsString('mskba-venues-map__scrim', $mapHtml);
        $this->assertStringContainsString('href="/venues"', $mapHtml);
        $this->assertStringNotContainsString('is-map-expanded', $mapJs);
        $this->assertStringNotContainsString('is-map-expanded', $mapCss);
        $this->assertStringNotContainsString('mskba:venues-map:expand-toggle', $workflow);
        app(PageBuilderMarkup::class)->validate($mapHtml, $mapCss);
    }
}
