<?php

namespace Tests\Feature\Venue;

use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeVenueRadiusSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_radius_is_applied_before_home_venue_search_limit(): void
    {
        foreach (range(1, 31) as $index) {
            $this->venueAt(
                name: sprintf('AA Far %02d', $index),
                latitude: 55.95,
                longitude: 37.80,
            );
        }

        $target = $this->venueAt(
            name: 'ZZ Nearby Hall',
            latitude: 55.7520,
            longitude: 37.6200,
        );

        $response = $this->getJson(route('home.venue-radius-search', [
            'latitude' => '55.7512440',
            'longitude' => '37.6184230',
            'radiusKm' => '3',
            'confirmed_only' => 1,
            'limit' => 30,
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'venues')
            ->assertJsonPath('venues.0.id', $target->id)
            ->assertJsonPath('venues.0.name', 'ZZ Nearby Hall');
    }

    private function venueAt(string $name, float $latitude, float $longitude): Venue
    {
        $address = Address::factory()->create([
            'city' => 'Москва',
            'street' => 'Тестовая улица',
            'building' => '1',
            'full_address' => 'Москва, Тестовая улица, 1',
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
        $location = Location::factory()->create(['address_id' => $address->id]);

        return Venue::factory()->create([
            'name' => $name,
            'status' => VenueStatusEnum::CONFIRMED->value,
            'location_id' => $location->id,
            'raw_address' => $address->full_address,
        ]);
    }
}
