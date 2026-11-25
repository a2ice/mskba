<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\Services\VenueCatalogLandingNavigation;
use Tests\TestCase;

final class VenueCatalogLandingDefaultConfigTest extends TestCase
{
    public function test_catalog_landings_are_enabled_by_default_and_can_be_disabled(): void
    {
        $navigation = app(VenueCatalogLandingNavigation::class);

        $this->assertTrue($navigation->enabled());

        config(['features.venue_catalog_landings.enabled' => false]);
        $this->assertFalse($navigation->enabled());
    }
}
