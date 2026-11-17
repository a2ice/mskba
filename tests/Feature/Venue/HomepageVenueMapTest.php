<?php

namespace Tests\Feature\Venue;

use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageVenueMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_feed_uses_real_confirmed_venues_and_coordinates(): void
    {
        $address = Address::factory()->create([
            'city' => 'Москва', 'latitude' => 55.7558, 'longitude' => 37.6173,
        ]);
        $location = Location::factory()->create(['address_id' => $address->id]);
        $venue = Venue::factory()->create([
            'name' => 'Наш баскетбольный зал',
            'status' => VenueStatusEnum::CONFIRMED->value,
            'location_id' => $location->id,
        ]);
        Venue::factory()->create(['status' => VenueStatusEnum::UNCONFIRMED->value]);

        $this->getJson(route('api.v1.home.venues-map'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $venue->id)
            ->assertJsonPath('data.0.latitude', 55.7558)
            ->assertJsonPath('data.0.longitude', 37.6173)
            ->assertJsonPath('data.0.url', route('venues.show', $venue->routeIdentifier()))
            ->assertJsonStructure(['data' => [['district', 'surfaces', 'amenities']], 'meta' => ['next_cursor']]);
    }

    public function test_homepage_map_feed_has_bounded_cursor_pagination(): void
    {
        Venue::factory()->count(3)->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $first = $this->getJson(route('api.v1.home.venues-map', ['limit' => 2]))
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', true);
        $this->getJson(route('api.v1.home.venues-map', [
            'limit' => 2, 'cursor' => $first->json('meta.next_cursor'),
        ]))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(route('api.v1.home.venues-map', ['cursor' => 'invalid']))
            ->assertUnprocessable();
    }
}
