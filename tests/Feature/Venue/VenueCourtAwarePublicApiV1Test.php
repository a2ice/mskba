<?php

namespace Tests\Feature\Venue;

use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueCourtAwarePublicApiV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_public_api_filters_by_actual_courts_before_cursor_pagination(): void
    {
        $venue = Venue::factory()->create([
            'name' => 'Школа №1794',
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => true,
            'requires_booking_approval' => true,
        ]);
        $outdoor = $venue->primaryCourt()->firstOrFail();
        $outdoor->update([
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
            'requires_booking_approval' => true,
            'requires_booking' => true,
        ]);
        $outdoorUrl = route('venues.courts.show', [$venue->routeIdentifier(), $outdoor->routeIdentifier()]);
        $indoorUrl = route('venues.courts.show', [$venue->routeIdentifier(), $indoor->routeIdentifier()]);

        $this->getJson(route('api.v1.venues.index', ['access' => 'free']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.url', $outdoorUrl)
            ->assertJsonPath('data.0.has_free_court', true)
            ->assertJsonPath('data.0.has_paid_court', true)
            ->assertJsonPath('data.0.requires_payment', null);

        $this->getJson(route('api.v1.venues.index', ['access' => 'paid']))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.url', $indoorUrl);

        $this->getJson(route('api.v1.venues.index', ['access' => 'approval']))
            ->assertOk()->assertJsonPath('data.0.url', $indoorUrl);

        $this->getJson(route('api.v1.venues.show', ['venue' => $venue->id]))
            ->assertOk()
            ->assertJsonPath('data.has_free_court', true)
            ->assertJsonPath('data.has_paid_court', true)
            ->assertJsonPath('data.has_approval_court', true)
            ->assertJsonPath('data.url', route('venues.show', $venue->routeIdentifier()));

        $this->getJson(route('api.v1.home.venues-map'))
            ->assertOk()
            ->assertJsonPath('data.0.has_free_court', true)
            ->assertJsonPath('data.0.has_paid_court', true);

        $outdoor->update(['requires_payment' => true]);
        $this->getJson(route('api.v1.venues.index', ['access' => 'free']))
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
