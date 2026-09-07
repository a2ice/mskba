<?php

namespace Tests\Feature\Location;

use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Location\Application\UseCases\CreateLocationHandler;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use App\Modules\Location\Domain\Models\MetroStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CreateLocationHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_address_is_saved_with_coordinates(): void
    {
        $location = app(CreateLocationHandler::class)->handle(new CreateLocationDTO(
            rawAddress: 'Россия, Москва, Летниковская улица, 12',
            city: 'Москва',
            street: 'Летниковская улица',
            building: '12',
            postalCode: '115114',
            latitude: 55.728,
            longitude: 37.644,
        ));

        $this->assertNotNull($location);
        $this->assertDatabaseHas('addresses', [
            'id' => $location->address_id,
            'city' => 'Москва',
            'street' => 'Летниковская улица',
            'building' => '12',
            'postal_code' => '115114',
            'full_address' => 'Россия, Москва, Летниковская улица, 12',
        ]);
    }

    public function test_raw_only_address_uses_default_city_fallback(): void
    {
        config(['integrations.address.default_city' => 'Москва']);

        $location = app(CreateLocationHandler::class)->handle(new CreateLocationDTO(
            rawAddress: 'Москва, неизвестный адрес',
        ));

        $this->assertNotNull($location);
        $this->assertDatabaseHas('addresses', [
            'id' => $location->address_id,
            'city' => 'Москва',
            'full_address' => 'Москва, неизвестный адрес',
        ]);
    }

    public function test_metro_station_ids_are_synced_to_location_pivot(): void
    {
        $station = MetroStation::factory()->create();

        $location = app(CreateLocationHandler::class)->handle(new CreateLocationDTO(
            rawAddress: 'Москва, Летниковская улица, 12',
            metroStationIds: [$station->id],
        ));

        $this->assertNotNull($location);
        $this->assertDatabaseHas('location_metro_station', [
            'location_id' => $location->id,
            'metro_station_id' => $station->id,
        ]);
    }

    public function test_city_and_district_directory_references_are_saved(): void
    {
        $city = City::factory()->create(['name' => 'Москва', 'alias' => 'moscow-test']);
        $district = District::factory()->for($city)->create();

        $location = app(CreateLocationHandler::class)->handle(new CreateLocationDTO(
            rawAddress: 'Москва, Тестовая улица, 1',
            cityId: $city->id,
            districtId: $district->id,
            street: 'Тестовая улица',
            building: '1',
        ));

        $this->assertNotNull($location);
        $this->assertDatabaseHas('addresses', [
            'id' => $location->address_id,
            'city_id' => $city->id,
            'district_id' => $district->id,
            'city' => 'Москва',
        ]);
    }

    public function test_recognized_city_name_is_linked_and_canonicalized_without_explicit_id(): void
    {
        $city = City::factory()->create([
            'name' => 'Москва',
            'alias' => 'moscow',
            'short_name' => 'Мск',
        ]);

        $location = app(CreateLocationHandler::class)->handle(new CreateLocationDTO(
            rawAddress: 'г. Москва, Тестовая улица, 2',
            city: 'г. Москва',
            street: 'Тестовая улица',
            building: '2',
        ));

        $this->assertNotNull($location);
        $this->assertDatabaseHas('addresses', [
            'id' => $location->address_id,
            'city_id' => $city->id,
            'city' => 'Москва',
        ]);
    }

    public function test_district_must_belong_to_selected_city(): void
    {
        $city = City::factory()->create();
        $otherCity = City::factory()->create();
        $district = District::factory()->for($otherCity)->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Выбранный район не относится к указанному городу.');

        app(CreateLocationHandler::class)->handle(new CreateLocationDTO(
            rawAddress: 'Тестовый адрес',
            cityId: $city->id,
            districtId: $district->id,
        ));
    }
}
