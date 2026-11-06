<?php

namespace Tests\Feature\Venue;

use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueCourtAwareSearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_and_home_map_find_the_same_eligible_court_with_independent_conditions(): void
    {
        $address = Address::factory()->create([
            'city' => 'Москва',
            'street' => 'Керамический проезд',
            'latitude' => 55.7520,
            'longitude' => 37.6200,
        ]);
        $location = Location::factory()->create(['address_id' => $address->id]);
        $venue = Venue::factory()->create([
            'name' => 'Школа №1794',
            'status' => VenueStatusEnum::CONFIRMED,
            'location_id' => $location->id,
            'requires_payment' => true,
            'requires_booking' => true,
            'requires_booking_approval' => true,
        ]);
        $outdoor = $venue->primaryCourt()->firstOrFail();
        $outdoor->update([
            'name' => 'Уличный корт',
            'requires_payment' => false,
            'requires_booking' => false,
            'requires_booking_approval' => false,
        ]);
        $indoor = $venue->courts()->create([
            'name' => 'Школьный зал',
            'alias' => 'indoor',
            'environment' => 'indoor',
            'sort_order' => 20,
            'requires_payment' => true,
            'requires_booking' => true,
            'requires_booking_approval' => true,
        ]);
        $freeUrl = route('venues.courts.show', [$venue->routeIdentifier(), $outdoor->routeIdentifier()]);
        $paidUrl = route('venues.courts.show', [$venue->routeIdentifier(), $indoor->routeIdentifier()]);

        $this->getJson(route('venues.search', ['confirmed_only' => 1, 'requires_payment' => 0]))
            ->assertOk()
            ->assertJsonCount(1, 'venues')
            ->assertJsonPath('venues.0.id', $venue->id)
            ->assertJsonPath('venues.0.url', $freeUrl)
            ->assertJsonPath('venues.0.matched_court.name', 'Уличный корт')
            ->assertJsonPath('venues.0.has_free_court', true)
            ->assertJsonPath('venues.0.has_paid_court', true)
            ->assertJsonPath('venues.0.has_free_access', true);

        $this->getJson(route('venues.search', ['confirmed_only' => 1, 'requires_payment' => 1]))
            ->assertOk()
            ->assertJsonPath('venues.0.url', $paidUrl)
            ->assertJsonPath('venues.0.matched_court.name', 'Школьный зал');

        // A paid hall and a separate hall without approval must NOT satisfy
        // a request for a paid hall without approval.
        $this->getJson(route('venues.search', [
            'confirmed_only' => 1,
            'requires_payment' => 1,
            'requires_booking_approval' => 0,
        ]))->assertOk()->assertJsonCount(0, 'venues');

        $home = route('home.venue-radius-search', [
            'latitude' => '55.7512440', 'longitude' => '37.6184230', 'radiusKm' => '3',
            'confirmed_only' => 1, 'requires_payment' => 0,
        ]);
        $this->getJson($home)->assertOk()
            ->assertJsonCount(1, 'venues')
            ->assertJsonPath('venues.0.url', $freeUrl)
            ->assertJsonPath('venues.0.has_free_court', true)
            ->assertJsonPath('venues.0.has_paid_court', true)
            ->assertJsonPath('venues.0.has_approval_court', true);

        $this->getJson(route('home.venue-radius-search', [
            'latitude' => '55.7512440', 'longitude' => '37.6184230', 'radiusKm' => '3',
            'confirmed_only' => 1, 'requires_booking_approval' => 1,
        ]))->assertOk()->assertJsonPath('venues.0.url', $paidUrl);

        // Refresh of a single court invalidates the cached search summary.
        $outdoor->update(['requires_payment' => true]);
        $this->getJson(route('venues.search', ['confirmed_only' => 1, 'requires_payment' => 0]))
            ->assertOk()->assertJsonCount(0, 'venues');
    }
}
