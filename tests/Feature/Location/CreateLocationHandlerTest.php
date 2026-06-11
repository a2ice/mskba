<?php

namespace Tests\Feature\Location;

use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Location\Application\UseCases\CreateLocationHandler;
use App\Modules\Location\Domain\Models\MetroStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
