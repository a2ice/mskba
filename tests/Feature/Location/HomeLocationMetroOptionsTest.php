<?php

namespace Tests\Feature\Location;

use App\Modules\Location\Domain\Models\MetroLine;
use App\Modules\Location\Domain\Models\MetroStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeLocationMetroOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_location_options_include_metro_station_coordinates(): void
    {
        $line = MetroLine::query()->create([
            'name' => 'Замоскворецкая',
            'color' => '#2f8c69',
            'sort_order' => 1,
        ]);

        $station = MetroStation::query()->create([
            'metro_line_id' => $line->id,
            'name' => 'Водный стадион',
            'latitude' => 55.8397,
            'longitude' => 37.4867,
            'sort_order' => 1,
        ]);

        $this->getJson(route('home.location-options'))
            ->assertOk()
            ->assertJsonPath('metro_stations.0.id', $station->id)
            ->assertJsonPath('metro_stations.0.name', 'Водный стадион')
            ->assertJsonPath('metro_stations.0.line_name', 'Замоскворецкая')
            ->assertJsonPath('metro_stations.0.latitude', 55.8397)
            ->assertJsonPath('metro_stations.0.longitude', 37.4867);
    }
}
