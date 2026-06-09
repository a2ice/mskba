<?php

namespace Tests\Feature\Location;

use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Location\Domain\Models\MetroLine;
use App\Modules\Location\Domain\Models\MetroStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_belongs_to_address_and_has_metro_stations(): void
    {
        $address = Address::factory()->create([
            'city' => 'Москва',
            'street' => 'Летниковская',
            'building' => '12',
            'latitude' => '55.7289000',
            'longitude' => '37.6448000',
        ]);
        $location = Location::factory()->create(['address_id' => $address->id]);
        $line = MetroLine::factory()->create(['name' => 'Замоскворецкая', 'color' => '#2aad55']);
        $station = MetroStation::factory()->create([
            'metro_line_id' => $line->id,
            'name' => 'Павелецкая',
        ]);

        $location->metroStations()->attach($station->id, [
            'distance_meters' => 650,
            'walking_time_minutes' => 8,
        ]);

        $location->refresh()->load('address', 'metroStations.line');

        $this->assertSame('Москва', $location->address->city);
        $this->assertSame('Летниковская', $location->address->street);
        $this->assertCount(1, $location->metroStations);
        $this->assertSame('Павелецкая', $location->metroStations->first()->name);
        $this->assertSame('Замоскворецкая', $location->metroStations->first()->line->name);
        $this->assertSame(650, $location->metroStations->first()->pivot->distance_meters);
        $this->assertSame(8, $location->metroStations->first()->pivot->walking_time_minutes);
    }
}
