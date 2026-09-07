<?php

namespace Tests\Feature\Location;

use App\Modules\Location\Application\Services\GeographyResolver;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeographyResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_city_and_moscow_administrative_district_are_resolved_from_normalized_names(): void
    {
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

        $resolved = app(GeographyResolver::class)->resolve(
            'г. Москва',
            ['Центральный административный округ'],
        );

        $this->assertSame($city->id, $resolved['city']?->id);
        $this->assertSame($district->id, $resolved['district']?->id);
    }

    public function test_khimki_microdistrict_prefix_is_ignored(): void
    {
        $city = City::factory()->create([
            'name' => 'Химки',
            'alias' => 'khimki',
        ]);
        $district = District::factory()->for($city)->create([
            'name' => 'Сходня',
            'alias' => 'skhodnya',
        ]);

        $resolved = app(GeographyResolver::class)->resolve(
            'городской округ Химки',
            ['мкр. Сходня'],
        );

        $this->assertSame($city->id, $resolved['city']?->id);
        $this->assertSame($district->id, $resolved['district']?->id);
    }

    public function test_unique_district_can_infer_city_when_yandex_city_name_is_not_recognized(): void
    {
        $city = City::factory()->create([
            'name' => 'Химки',
            'alias' => 'khimki',
        ]);
        $district = District::factory()->for($city)->create([
            'name' => 'Новокуркино',
            'alias' => 'novokurkino',
        ]);

        $resolved = app(GeographyResolver::class)->resolve(
            null,
            ['район Новокуркино'],
        );

        $this->assertSame($city->id, $resolved['city']?->id);
        $this->assertSame($district->id, $resolved['district']?->id);
    }

    public function test_unknown_geography_is_not_created_automatically(): void
    {
        $resolved = app(GeographyResolver::class)->resolve(
            'Неизвестный город',
            ['Неизвестный район'],
        );

        $this->assertNull($resolved['city']);
        $this->assertNull($resolved['district']);
        $this->assertDatabaseCount('cities', 0);
        $this->assertDatabaseCount('districts', 0);
    }
}
