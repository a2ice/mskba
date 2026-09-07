<?php

namespace Tests\Feature\Location;

use App\Modules\Location\Application\Services\AddressSuggestService;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use App\Modules\Location\Domain\Models\MetroLine;
use App\Modules\Location\Domain\Models\MetroStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AddressSuggestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_metro_name_is_matched_from_yandex_components(): void
    {
        config(['integrations.yandex.api_key' => 'test-key']);

        $line = MetroLine::factory()->create(['name' => 'Замоскворецкая линия']);
        $station = MetroStation::factory()->create([
            'metro_line_id' => $line->id,
            'name' => 'Павелецкая',
        ]);

        Http::fake([
            'suggest-maps.yandex.ru/*' => Http::response(['results' => []]),
            'geocode-maps.yandex.ru/*' => Http::response($this->geocodeResponse(
                metroNames: ['м. Павелецкая'],
            )),
        ]);

        $suggestions = app(AddressSuggestService::class)->suggest('Москва Летниковская 12');

        $this->assertSame([$station->id], $suggestions[0]['metro_station_ids']);
        $this->assertSame(['Павелецкая (Замоскворецкая линия)'], $suggestions[0]['metro_station_labels']);
    }

    public function test_nearest_metro_is_used_when_yandex_does_not_return_metro_name(): void
    {
        config(['integrations.yandex.api_key' => 'test-key']);

        $line = MetroLine::factory()->create(['name' => 'Сокольническая линия']);
        $nearest = MetroStation::factory()->create([
            'metro_line_id' => $line->id,
            'name' => 'Парк культуры',
            'latitude' => 55.7350000,
            'longitude' => 37.5940000,
        ]);
        MetroStation::factory()->create([
            'name' => 'Далекая',
            'latitude' => 55.9000000,
            'longitude' => 37.9000000,
        ]);

        Http::fake([
            'suggest-maps.yandex.ru/*' => Http::response(['results' => []]),
            'geocode-maps.yandex.ru/*' => Http::response($this->geocodeResponse(
                latitude: 55.7351000,
                longitude: 37.5941000,
            )),
        ]);

        $suggestions = app(AddressSuggestService::class)->suggest('Москва Остоженка 10');

        $this->assertSame([$nearest->id], $suggestions[0]['metro_station_ids']);
    }

    public function test_reverse_geocode_uses_common_mapping_and_nearest_metro(): void
    {
        config(['integrations.yandex.api_key' => 'test-key']);

        $nearest = MetroStation::factory()->create([
            'name' => 'Парк культуры',
            'latitude' => 55.7350000,
            'longitude' => 37.5940000,
        ]);

        Http::fake([
            'geocode-maps.yandex.ru/*' => Http::response($this->geocodeResponse(
                latitude: 55.7351000,
                longitude: 37.5941000,
            )),
        ]);

        $suggestion = app(AddressSuggestService::class)->reverse(55.7351, 37.5941);

        $this->assertNotNull($suggestion);
        $this->assertSame('Москва, Летниковская улица, 12', $suggestion['label']);
        $this->assertSame([$nearest->id], $suggestion['metro_station_ids']);
        $this->assertTrue($suggestion['has_house']);
    }

    public function test_yandex_administrative_components_are_resolved_to_city_and_district_ids(): void
    {
        config(['integrations.yandex.api_key' => 'test-key']);

        $city = City::factory()->create([
            'name' => 'Москва',
            'alias' => 'moscow',
            'short_name' => 'Мск',
        ]);
        $district = District::factory()->for($city)->create([
            'name' => 'Центральный административный округ',
            'alias' => 'cao',
            'short_name' => 'ЦАО',
        ]);

        Http::fake([
            'suggest-maps.yandex.ru/*' => Http::response(['results' => []]),
            'geocode-maps.yandex.ru/*' => Http::response($this->geocodeResponse(
                administrativeComponents: [
                    ['kind' => 'area', 'name' => 'Центральный административный округ'],
                    ['kind' => 'district', 'name' => 'район Замоскворечье'],
                ],
            )),
        ]);

        $suggestions = app(AddressSuggestService::class)->suggest('Москва Летниковская 12');

        $this->assertSame($city->id, $suggestions[0]['city_id']);
        $this->assertSame('Москва', $suggestions[0]['city']);
        $this->assertSame($district->id, $suggestions[0]['district_id']);
        $this->assertSame('Центральный административный округ', $suggestions[0]['district']);
    }

    public function test_reverse_geocode_resolves_khimki_microdistrict(): void
    {
        config(['integrations.yandex.api_key' => 'test-key']);

        $city = City::factory()->create([
            'name' => 'Химки',
            'alias' => 'khimki',
        ]);
        $district = District::factory()->for($city)->create([
            'name' => 'Сходня',
            'alias' => 'skhodnya',
        ]);

        Http::fake([
            'geocode-maps.yandex.ru/*' => Http::response($this->geocodeResponse(
                city: 'Химки',
                street: 'улица Кирова',
                administrativeComponents: [
                    ['kind' => 'district', 'name' => 'мкр. Сходня'],
                ],
            )),
        ]);

        $suggestion = app(AddressSuggestService::class)->reverse(55.95, 37.30);

        $this->assertNotNull($suggestion);
        $this->assertSame($city->id, $suggestion['city_id']);
        $this->assertSame($district->id, $suggestion['district_id']);
    }

    /**
     * @param  array<int, string>  $metroNames
     * @param  array<int, array{kind: string, name: string}>  $administrativeComponents
     * @return array<string, mixed>
     */
    private function geocodeResponse(
        array $metroNames = [],
        float $latitude = 55.728,
        float $longitude = 37.644,
        string $city = 'Москва',
        string $street = 'Летниковская улица',
        string $building = '12',
        array $administrativeComponents = [],
    ): array {
        $components = [
            ['kind' => 'country', 'name' => 'Россия'],
            ['kind' => 'locality', 'name' => $city],
            ...$administrativeComponents,
            ['kind' => 'street', 'name' => $street],
            ['kind' => 'house', 'name' => $building],
        ];

        foreach ($metroNames as $metroName) {
            $components[] = ['kind' => 'metro', 'name' => $metroName];
        }

        return [
            'response' => [
                'GeoObjectCollection' => [
                    'featureMember' => [[
                        'GeoObject' => [
                            'metaDataProperty' => [
                                'GeocoderMetaData' => [
                                    'text' => "Россия, {$city}, {$street}, {$building}",
                                    'Address' => [
                                        'postal_code' => '115114',
                                        'Components' => $components,
                                    ],
                                ],
                            ],
                            'Point' => [
                                'pos' => "{$longitude} {$latitude}",
                            ],
                        ],
                    ]],
                ],
            ],
        ];
    }
}
