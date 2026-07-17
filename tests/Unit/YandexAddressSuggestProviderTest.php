<?php

namespace Tests\Unit;

use App\Modules\Location\Infrastructure\Yandex\YandexAddressSuggestProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YandexAddressSuggestProviderTest extends TestCase
{
    public function test_missing_api_key_returns_empty_suggestions_without_http_call(): void
    {
        config(['integrations.yandex.api_key' => null]);
        Http::fake();

        $this->assertSame([], app(YandexAddressSuggestProvider::class)->suggest('Москва Летниковская 12'));

        Http::assertNothingSent();
    }

    public function test_geocode_fallback_is_used_when_suggest_is_empty(): void
    {
        config(['integrations.yandex.api_key' => 'test-key']);

        Http::fake([
            'suggest-maps.yandex.ru/*' => Http::response(['results' => []]),
            'geocode-maps.yandex.ru/*' => Http::response($this->geocodeResponse()),
        ]);

        $suggestions = app(YandexAddressSuggestProvider::class)->suggest('Москва Летниковская 12');

        $this->assertCount(1, $suggestions);
        $this->assertSame('Россия, Москва, Летниковская улица, 12', $suggestions[0]->label);
        $this->assertSame('Москва', $suggestions[0]->city);
        $this->assertSame('Летниковская улица', $suggestions[0]->street);
        $this->assertSame('12', $suggestions[0]->building);
        $this->assertSame('115114', $suggestions[0]->postalCode);
        $this->assertSame(55.728, $suggestions[0]->latitude);
        $this->assertSame(37.644, $suggestions[0]->longitude);
    }

    public function test_results_without_house_are_filtered_out(): void
    {
        config(['integrations.yandex.api_key' => 'test-key']);

        Http::fake([
            'suggest-maps.yandex.ru/*' => Http::response(['results' => []]),
            'geocode-maps.yandex.ru/*' => Http::response($this->geocodeResponse(building: null)),
        ]);

        $this->assertSame([], app(YandexAddressSuggestProvider::class)->suggest('Москва Летниковская'));
    }

    public function test_coordinates_are_reverse_geocoded_to_house(): void
    {
        config(['integrations.yandex.api_key' => 'test-key']);

        Http::fake([
            'geocode-maps.yandex.ru/*' => Http::response($this->geocodeResponse()),
        ]);

        $suggestion = app(YandexAddressSuggestProvider::class)->reverse(55.728, 37.644);

        $this->assertNotNull($suggestion);
        $this->assertSame('Россия, Москва, Летниковская улица, 12', $suggestion->label);
        Http::assertSent(fn ($request): bool => $request['geocode'] === '37.644,55.728'
            && $request['kind'] === 'house');
    }

    private function geocodeResponse(?string $building = '12'): array
    {
        $components = [
            ['kind' => 'country', 'name' => 'Россия'],
            ['kind' => 'locality', 'name' => 'Москва'],
            ['kind' => 'street', 'name' => 'Летниковская улица'],
        ];

        if ($building !== null) {
            $components[] = ['kind' => 'house', 'name' => $building];
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
                                'pos' => '37.644 55.728',
                            ],
                        ],
                    ]],
                ],
            ],
        ];
    }
}
