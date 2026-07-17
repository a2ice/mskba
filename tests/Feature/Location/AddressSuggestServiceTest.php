<?php

namespace Tests\Feature\Location;

use App\Modules\Location\Application\Services\AddressSuggestService;
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
        $this->assertSame('Россия, Москва, Летниковская улица, 12', $suggestion['label']);
        $this->assertSame([$nearest->id], $suggestion['metro_station_ids']);
        $this->assertTrue($suggestion['has_house']);
    }

    /**
     * @param  array<int, string>  $metroNames
     * @return array<string, mixed>
     */
    private function geocodeResponse(
        array $metroNames = [],
        float $latitude = 55.728,
        float $longitude = 37.644,
    ): array {
        $components = [
            ['kind' => 'country', 'name' => 'Россия'],
            ['kind' => 'locality', 'name' => 'Москва'],
            ['kind' => 'street', 'name' => 'Летниковская улица'],
            ['kind' => 'house', 'name' => '12'],
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
                                    'text' => 'Россия, Москва, Летниковская улица, 12',
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
