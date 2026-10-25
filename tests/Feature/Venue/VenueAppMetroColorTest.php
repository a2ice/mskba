<?php

namespace Tests\Feature\Venue;

use App\Modules\Location\Domain\Models\Location;
use App\Modules\Location\Domain\Models\MetroLine;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class VenueAppMetroColorTest extends TestCase
{
    use RefreshDatabase;

    public function test_metro_dots_use_line_hex_with_or_without_hash_and_safe_fallback(): void
    {
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(\App\Presentation\Theming\ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));

        $location = Location::factory()->create();
        foreach ([
            ['Яхромская', 'BED12C'],
            ['Селигерская', '#BED12C'],
            ['Некорректный цвет', 'not-a-color'],
        ] as [$name, $color]) {
            $line = MetroLine::factory()->create(['color' => $color]);
            $station = MetroStation::factory()->create(['metro_line_id' => $line->id, 'name' => $name]);
            $location->metroStations()->attach($station->id);
        }

        $venue = Venue::factory()->create([
            'location_id' => $location->id,
            'status' => VenueStatusEnum::CONFIRMED,
        ]);

        $response = $this->get(route('venues.show', $venue->routeIdentifier()))->assertOk();
        $html = $response->getContent();

        $this->assertSame(2, substr_count($html, '--metro-color: #BED12C'));
        $this->assertSame(1, substr_count($html, '--metro-color: #758392'));
        $response->assertSee('Яхромская')->assertSee('Селигерская');
    }
}
