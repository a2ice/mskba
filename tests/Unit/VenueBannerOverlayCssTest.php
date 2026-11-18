<?php

namespace Tests\Unit;

use App\Modules\Venue\Domain\Models\Venue;
use PHPUnit\Framework\TestCase;

final class VenueBannerOverlayCssTest extends TestCase
{
    public function test_legacy_opacity_remains_available(): void
    {
        $venue = new Venue;
        $venue->banner_overlay_opacity = 25;

        $this->assertSame('rgba(0,0,0,0.25)', $venue->bannerOverlayCss());
    }

    public function test_css_value_is_returned_unchanged(): void
    {
        $venue = new Venue;
        $venue->banner_overlay_color = 'rgba(255,255,255,0.5)';
        $this->assertSame('rgba(255,255,255,0.5)', $venue->bannerOverlayCss());

        $venue->banner_overlay_color = 'linear-gradient(90deg, #fff7, #1113)';
        $this->assertSame('linear-gradient(90deg, #fff7, #1113)', $venue->bannerOverlayCss());
    }
}
