<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\PageBuilderMarkup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenuesMapExpandToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_expandable_overlay_retains_approved_html_and_stores_previous_asset_snapshots(): void
    {
        $html = file_get_contents(resource_path('page-builder/venues-map.html'));
        $css = file_get_contents(resource_path('page-builder/venues-map.css'));
        $js = file_get_contents(resource_path('page-builder/venues-map.js'));
        $previousCss = file_get_contents(resource_path('page-builder/versions/venues-map-pre-expand-20261026.css'));
        $previousJs = file_get_contents(resource_path('page-builder/versions/venues-map-pre-expand-20261026.js'));

        $this->assertNotSame($css, $previousCss);
        $this->assertNotSame($js, $previousJs);
        $this->assertStringContainsString('mskba-venues-map__scrim', $html);
        $this->assertStringContainsString('href="/venues"', $html, 'CTA remains a usable link without JS.');
        $this->assertStringContainsString('is-map-expanded', $js);
        $this->assertStringContainsString('copy.inert = expanded', $js);
        $this->assertStringContainsString("aria-label', 'Свернуть карту", $js);
        $this->assertStringContainsString('.mskba-venues-map.is-map-expanded .mskba-venues-map__scrim', $css);
        $this->assertStringContainsString('translateX(-100%)', $css);
        $this->assertStringContainsString('.mskba-venues-map__collapse', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);

        // Published component CSS must remain acceptable to the strict Page Builder sanitizer.
        app(PageBuilderMarkup::class)->validate($html, $css);
    }

    public function test_map_publisher_cannot_touch_non_staging_database(): void
    {
        $this->artisan('mskba:venues-map:expand-toggle', ['--apply' => true])
            ->assertExitCode(1);
    }
}
