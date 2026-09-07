<?php

namespace Tests\Feature\Venue;

use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueSearchLocationFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_city_and_street_are_applied_before_venue_search_limit(): void
    {
        foreach (range(1, 31) as $index) {
            $this->venueAt(
                name: sprintf('AA Decoy %02d', $index),
                city: 'Химки',
                street: 'Юбилейный проспект',
            );
        }

        $target = $this->venueAt(
            name: 'ZZ Target Hall',
            city: 'Москва',
            street: 'Ленинградский проспект',
        );

        $this->venueAt(
            name: 'ZZ Wrong Moscow Street',
            city: 'Москва',
            street: 'Тверская улица',
        );

        $response = $this->getJson(route('venues.search', [
            'city' => 'Москва',
            'street' => 'Ленинградский проспект',
            'confirmed_only' => 1,
            'limit' => 30,
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'venues')
            ->assertJsonPath('venues.0.id', $target->id)
            ->assertJsonPath('venues.0.name', 'ZZ Target Hall');
    }

    private function venueAt(string $name, string $city, string $street): Venue
    {
        $address = Address::factory()->create([
            'city' => $city,
            'street' => $street,
            'building' => '1',
            'full_address' => "{$city}, {$street}, 1",
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
