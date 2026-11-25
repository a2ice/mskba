<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Application\Services\RentalSeoInventory;
use App\Modules\Venue\Application\Services\RentalSeoLandingData;
use App\Modules\Venue\Application\UseCases\ListVenuesHandler;
use App\Modules\Venue\Domain\Enums\VenueCourtEnvironmentEnum;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class RentalSeoInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_distinguishes_paid_from_configured_rental_and_excludes_non_public_venues(): void
    {
        config(['features.venue_rental.rental_flow' => false]);
        $city = City::factory()->create(['alias' => 'seo-test-city']);
        $district = District::factory()->for($city)->create(['alias' => 'seo-test-district']);
        $address = Address::factory()->create(['city_id' => $city->id, 'district_id' => $district->id, 'latitude' => 55.75, 'longitude' => 37.62]);
        $location = Location::factory()->create(['address_id' => $address->id]);

        $make = function (array $overrides = []) use ($location): Venue {
            $venue = Venue::factory()->create([
                'location_id' => $location->id,
                'is_published' => true,
                'status' => VenueStatusEnum::CONFIRMED,
                ...$overrides,
            ]);
            $venue->primaryCourt()->firstOrFail()->update([
                'environment' => VenueCourtEnvironmentEnum::INDOOR,
                'operational_status' => VenueOperationalStatusEnum::ACTIVE,
                'requires_payment' => true,
                'allows_whole' => true,
                'rental_enabled' => true,
            ]);

            return $venue;
        };

        $configured = $make();
        $make(); // Paid, but no active rental policy.
        $closed = $make();
        $closed->primaryCourt()->firstOrFail()->update(['operational_status' => VenueOperationalStatusEnum::TEMPORARILY_CLOSED]);
        $disabled = $make();
        $disabled->primaryCourt()->firstOrFail()->update(['rental_enabled' => false]);
        $make(['is_published' => false]);
        $make(['status' => VenueStatusEnum::UNCONFIRMED]);
        $outdoor = $make();
        $outdoor->primaryCourt()->firstOrFail()->update(['environment' => VenueCourtEnvironmentEnum::OUTDOOR]);

        foreach ([$configured, $closed, $disabled] as $venue) {
            VenueBookingPolicy::query()->create([
                'venue_id' => $venue->id,
                'version' => 1,
                'is_enabled' => true,
                'allows_whole' => true,
                'allows_halves' => false,
                'minimum_duration_minutes' => 60,
                'maximum_duration_minutes' => 240,
                'time_step_minutes' => 30,
                'minimum_lead_time_minutes' => 120,
                'maximum_advance_days' => 90,
                'currency' => 'RUB',
                'whole_price_per_step_minor' => 100000,
                'half_price_per_step_minor' => 60000,
                'hold_duration_minutes' => 30,
                'allows_hold_extension' => false,
                'maximum_hold_extension_minutes' => null,
                'requires_payment' => true,
                'payment_window_minutes' => 15,
                'quote_validity_minutes' => 15,
                'cancellation_before_minutes' => 1440,
                'published_by_user_id' => User::factory()->create()->id,
                'published_at' => now(),
                'active_marker' => true,
            ]);
        }

        $report = app(RentalSeoInventory::class)->snapshot();
        $this->assertFalse($report['rental_flow_feature_enabled']);
        $this->assertSame(5, $report['totals']['venues']);
        $this->assertSame(4, $report['totals']['indoor_courts']);
        $this->assertSame(3, $report['totals']['paid_open_indoor_courts']);
        $this->assertSame(1, $report['totals']['rental_configured_indoor_courts']);
        $this->assertSame(1, $report['totals']['paid_rental_configured_indoor_courts']);
        $this->assertSame(1, $report['totals']['venues_with_configured_indoor_rental']);
        $this->assertCount(1, $report['geography']);
        $this->assertEquals($city->id, $report['geography'][0]['city_id']);
        $this->assertEquals($district->id, $report['geography'][0]['district_id']);
        $this->artisan('mskba:seo:rental-inventory')->assertSuccessful();

        $landing = app(RentalSeoLandingData::class);
        $this->assertNull($landing->forGeography('missing-city'));
        $this->assertNull($landing->forGeography($city->alias, 'missing-district'));
        $data = $landing->forGeography($city->alias, $district->alias);
        $this->assertFalse($data['rental_flow_enabled']);
        $this->assertSame(1, $data['venue_count']);
        $this->assertSame($configured->id, $data['venues'][0]->id);
        $this->assertSame($configured->primaryCourt()->firstOrFail()->publicName(), $data['venues'][0]->matchedCourtName);
        $this->assertSame(route('venues.courts.show', [
            $configured->routeIdentifier(), $configured->primaryCourt()->firstOrFail()->routeIdentifier(),
        ]), $data['venues'][0]->matchedCourtUrl);
        $this->assertStringContainsString('city_id='.$city->id, $data['catalog_url']);
        $this->assertStringContainsString('district_id='.$district->id, $data['catalog_url']);

        // Existing catalog remains broader than the rental-specific projection.
        $catalog = app(ListVenuesHandler::class)->handle(null, filters: [
            'city_id' => $city->id, 'type' => 'sports_hall', 'operational_status' => 'active',
        ]);
        $this->assertCount(3, $catalog);

        // A venue may have several halls. Never direct visitors to a hall
        // without configured rental merely because it is the first in order.
        $first = $configured->primaryCourt()->firstOrFail();
        $second = $first->replicate();
        $second->fill([
            'name' => 'Configured secondary hall', 'alias' => 'configured-secondary-hall',
            'is_primary' => false, 'sort_order' => 100, 'rental_enabled' => true,
        ]);
        $second->save();
        $first->update(['rental_enabled' => false]);
        $data = $landing->forGeography($city->alias);
        $this->assertSame(1, $data['venue_count']);
        $this->assertSame($second->publicName(), $data['venues'][0]->matchedCourtName);
        $this->assertSame(route('venues.courts.show', [
            $configured->routeIdentifier(), $second->routeIdentifier(),
        ]), $data['venues'][0]->matchedCourtUrl);

        $this->withoutVite();
        config(['themes.active' => 'mskba_app']);
        $this->app->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));

        $catalogUrl = route('venues', ['rental_configured' => 1]);
        $this->get($catalogUrl)->assertOk()
            ->assertSee('data-context-result-total="1"', false)
            ->assertSee(route('venues.courts.show', [$configured->routeIdentifier(), $second->routeIdentifier()]), false);
        $this->getJson(route('venues', [
            'rental_configured' => 1, 'city_id' => $city->id, 'district_id' => $district->id,
            'type' => 'sports_hall', 'fragment' => 'items',
        ]))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('has_more', false);
        $this->get(route('venues', ['rental_configured' => 1, 'view' => 'map']))
            ->assertOk()->assertSee('data-context-result-total="1"', false)
            ->assertSee('Configured secondary hall');
        $this->getJson(route('venues', ['rental_configured' => 0, 'fragment' => 'items']))
            ->assertOk()->assertJsonPath('total', 5);
        $this->getJson(route('venues', ['rental_configured' => 'invalid']))
            ->assertUnprocessable()->assertJsonValidationErrors('rental_configured');

        $second->update(['rental_enabled' => false]);
        $this->getJson(route('venues', ['rental_configured' => 1, 'fragment' => 'items']))
            ->assertOk()->assertJsonPath('total', 0);
        $this->assertSame(0, $landing->forGeography($city->alias)['venue_count']);
    }
}
