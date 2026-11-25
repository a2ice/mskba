<?php

namespace Tests\Feature\Venue;

use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Application\UseCases\ListVenuesHandler;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class VenueCatalogGeographyTest extends TestCase
{
    use RefreshDatabase;

    public function test_geography_filters_preserve_visibility_and_work_before_pagination(): void
    {
        config(['themes.active' => 'mskba_app']);
        $this->app->forgetInstance(ThemeResolver::class);
        View::getFinder()->replaceNamespace('theme', resource_path('themes/mskba_app/views'));

        $city = City::factory()->create();
        $otherCity = City::factory()->create();
        $district = District::factory()->for($city)->create();
        $otherDistrict = District::factory()->for($city)->create();
        $make = function (string $name, City $city, ?District $district, bool $published = true): Venue {
            $address = Address::factory()->create([
                'city_id' => $city->id, 'district_id' => $district?->id,
                'latitude' => 55.75, 'longitude' => 37.62,
            ]);
            $location = Location::factory()->create(['address_id' => $address->id]);

            return Venue::factory()->create([
                'name' => $name, 'location_id' => $location->id,
                'status' => VenueStatusEnum::CONFIRMED, 'is_published' => $published,
            ]);
        };

        $matches = collect(range(1, 5))->map(fn ($n) => $make(sprintf('GeoMatch%02d', $n), $city, $district));
        $sameCity = $make('GeoOtherDistrict', $city, $otherDistrict);
        $noDistrict = $make('GeoNoDistrict', $city, null);
        $make('GeoOtherCity', $otherCity, null);
        $make('GeoHidden', $city, $district, false);

        $handler = app(ListVenuesHandler::class);
        $this->assertEqualsCanonicalizing($matches->pluck('id')->all(), collect(
            $handler->handle(null, filters: ['district_id' => $district->id])
        )->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            $matches->pluck('id')->concat([$sameCity->id, $noDistrict->id])->all(),
            collect($handler->handle(null, filters: ['city_id' => $city->id]))->pluck('id')->all(),
        );
        $this->assertSame([], $handler->handle(null, filters: ['city_id' => $otherCity->id, 'district_id' => $district->id]));

        $this->get(route('venues'))->assertOk()
            ->assertSee('data-venue-geography-district-field hidden', false);
        $this->get(route('venues', ['district_id' => $district->id]))->assertOk()
            ->assertDontSee('data-venue-geography-district-field hidden', false)
            ->assertSee('GeoMatch01')->assertDontSee('GeoOtherDistrict');

        $filters = ['city_id' => $city->id, 'district_id' => $district->id];
        $this->get(route('venues', $filters))->assertOk()
            ->assertSee('GeoMatch01')->assertDontSee('GeoMatch05')->assertDontSee('GeoOtherDistrict')
            ->assertSee('name="city_id"', false)->assertSee('name="district_id"', false);
        $response = $this->getJson(route('venues', $filters + ['page' => 2, 'fragment' => 'items']))
            ->assertOk()->assertJsonPath('total', 5)->assertJsonPath('has_more', false);
        $this->assertStringContainsString('GeoMatch05', $response->json('html'));
        $this->assertStringNotContainsString('GeoOtherDistrict', $response->json('html'));

        $this->get(route('venues', $filters + ['view' => 'map']))->assertOk()
            ->assertSee('GeoMatch05')->assertDontSee('GeoHidden')->assertDontSee('GeoOtherCity');
        $this->getJson(route('venues', ['city_id' => -1]))->assertUnprocessable()->assertJsonValidationErrors('city_id');
        $this->getJson(route('venues', ['district_id' => -1]))->assertUnprocessable()->assertJsonValidationErrors('district_id');
    }
}
