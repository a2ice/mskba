<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenuesMapZoomChevronTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_uses_one_zoom_step_out_with_centered_filter_chevrons(): void
    {
        $js = file_get_contents(resource_path('page-builder/venues-map.js'));
        $css = file_get_contents(resource_path('page-builder/venues-map.css'));
        $oldJs = file_get_contents(resource_path('page-builder/versions/venues-map-before-zoom.js'));
        $oldCss = file_get_contents(resource_path('page-builder/versions/venues-map-before-zoom.css'));

        $this->assertStringContainsString('zoom: isMobile ? 9 : 9.5', $js);
        $this->assertStringContainsString('zoom: isMobile ? 10 : 10.5', $oldJs);
        $this->assertStringContainsString("top: 50%;", $css);
        $this->assertStringContainsString("transform: translateY(-65%) rotate(45deg)", $css);
        $this->assertStringContainsString("content: '⌄'; position: absolute; right: 6px; top: 8px", $oldCss);
        $this->assertStringNotContainsString('openCard(items[0].venue,items[0].marker)', $js);
    }

    public function test_publisher_refuses_non_staging_environment(): void
    {
        $this->artisan('mskba:venues-map:zoom-chevrons', ['--apply' => true])
            ->assertExitCode(1);
    }
}
