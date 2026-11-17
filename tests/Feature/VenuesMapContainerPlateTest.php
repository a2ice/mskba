<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\PageBuilderMarkup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenuesMapContainerPlateTest extends TestCase
{
    use RefreshDatabase;

    public function test_entire_interactive_map_is_inside_one_constrained_container(): void
    {
        $html = file_get_contents(resource_path('page-builder/venues-map.html'));
        $css = file_get_contents(resource_path('page-builder/venues-map.css'));
        $js = file_get_contents(resource_path('page-builder/venues-map.js'));

        $this->assertStringContainsString(
            '<div class="container mskba-venues-map-section__container">',
            $html,
        );
        $this->assertStringContainsString(
            '<section class="mskba-venues-map"',
            $html,
        );
        $this->assertStringNotContainsString(
            '<div class="container mskba-venues-map__container">',
            $html,
        );
        $this->assertSame(1, substr_count($html, 'class="container '));
        $this->assertStringContainsString('id="mskba-venues-map-canvas"', $html);
        $this->assertStringContainsString('id="mskba-venues-map-card"', $html);
        $this->assertStringContainsString('id="mskba-venues-map-filters"', $html);

        $this->assertStringContainsString('.mskba-venues-map-section', $css);
        $this->assertStringContainsString('border-radius: 21px', $css);
        $this->assertStringContainsString('overflow: hidden', $css);
        $this->assertStringContainsString('padding: 0 clamp(24px,3vw,43px)', $css);
        $this->assertStringNotContainsString('mskba-map-top-extension', $css);
        $this->assertStringNotContainsString('mskba-map-bottom-extension', $css);
        $this->assertStringNotContainsString('.mskba-venues-map__scrim::before', $css);
        $this->assertStringNotContainsString(
            'openCard(items[0].venue,items[0].marker)', $js,
        );
        $this->assertStringContainsString("marker.events.add('click'", $js);

        // The Filament sanitizer must keep the outer container structure.
        $sanitized = app(PageBuilderMarkup::class)->html($html);
        $this->assertStringContainsString(
            'mskba-venues-map-section__container', $sanitized,
        );
        app(PageBuilderMarkup::class)->validate($html, $css);
    }

    public function test_rollback_baselines_are_preserved(): void
    {
        foreach (['html', 'css'] as $extension) {
            $previous = resource_path(
                'page-builder/versions/venues-map-live-v4-20261022.'.$extension,
            );
            $current = resource_path('page-builder/venues-map.'.$extension);
            $this->assertFileExists($previous);
            $this->assertNotSame(file_get_contents($previous), file_get_contents($current));
        }
    }

    public function test_command_refuses_to_publish_to_non_staging_environment(): void
    {
        $this->artisan('mskba:venues-map:container-plate', ['--apply' => true])
            ->assertExitCode(1);
    }
}
