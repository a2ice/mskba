<?php

namespace Tests\Feature\Event;

use Illuminate\Routing\Route;
use Tests\TestCase;

final class VenueRentalLegacyRouteSnapshotTest extends TestCase
{
    public function test_legacy_rental_entry_points_match_the_recorded_snapshot(): void
    {
        $snapshotPath = base_path('tests/Fixtures/venue-rental-legacy-routes.json');
        $expected = json_decode((string) file_get_contents($snapshotPath), true, flags: JSON_THROW_ON_ERROR);
        $routeNames = array_column($expected, 'name');

        $actual = collect(app('router')->getRoutes()->getRoutes())
            ->filter(static fn (Route $route): bool => in_array($route->getName(), $routeNames, true))
            ->map(static fn (Route $route): array => [
                'method' => implode('|', $route->methods()),
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'action' => $route->getActionName(),
                'middleware' => $route->gatherMiddleware(),
            ])
            ->sortBy('name')
            ->values()
            ->all();

        $expected = collect($expected)->sortBy('name')->values()->all();

        $this->assertSame($expected, $actual);
    }
}
