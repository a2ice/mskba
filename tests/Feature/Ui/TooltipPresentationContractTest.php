<?php

namespace Tests\Feature\Ui;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TooltipPresentationContractTest extends TestCase
{
    #[Test]
    public function title_tooltips_use_semantic_text_and_visual_presentations(): void
    {
        $javascript = file_get_contents(
            resource_path('themes/mskba_dark/js/features/tooltips.js'),
        );
        $css = file_get_contents(
            resource_path('themes/mskba_dark/css/tooltip.css'),
        );

        $this->assertIsString($javascript);
        $this->assertIsString($css);

        $this->assertStringContainsString('ui-tooltip-source--text', $javascript);
        $this->assertStringContainsString('ui-tooltip-source--visual', $javascript);
        $this->assertStringContainsString('data-tooltip-generated', $javascript);
        $this->assertStringContainsString('[data-tooltip-visual], [data-tooltip-icon]', $javascript);
        $this->assertStringContainsString('MutationObserver', $javascript);
        $this->assertStringNotContainsString("element.attr('data-tooltip-variant')", $javascript);

        $this->assertStringContainsString('.ui-tooltip-source--text,', $css);
        $this->assertStringContainsString('.ui-tooltip-source--visual,', $css);
    }

    #[Test]
    public function ambiguous_visual_markers_are_explicit_and_plain_text_labels_are_not_icon_sources(): void
    {
        $teamCatalog = file_get_contents(
            resource_path('themes/mskba_dark/views/pages/teams/partials/catalog-item.blade.php'),
        );
        $facilitiesEditor = file_get_contents(
            resource_path('themes/mskba_dark/views/partials/venues/facilities-editor.blade.php'),
        );
        $bookingSelector = file_get_contents(
            resource_path('themes/mskba_dark/views/partials/venues/predictive-selector.blade.php'),
        );

        $this->assertIsString($teamCatalog);
        $this->assertIsString($facilitiesEditor);
        $this->assertIsString($bookingSelector);

        $this->assertStringContainsString('data-tooltip-visual', $teamCatalog);
        $this->assertStringNotContainsString('data-tooltip-icon>Количество колец', $facilitiesEditor);
        $this->assertStringNotContainsString('data-tooltip-icon>Игровая зона', $bookingSelector);
    }
}
