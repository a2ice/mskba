<?php

namespace Database\Seeders;

use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

class GeographySeeder extends Seeder
{
    public function run(): void
    {
        $seededCities = [];

        foreach ($this->cities() as $cityData) {
            $districts = $cityData['districts'];
            unset($cityData['districts']);

            $seedAlias = $cityData['alias'];
            $city = $this->findSeedCity($cityData)
                ?? City::query()->create($cityData);
            $seededCities[$seedAlias] = $city;

            foreach ($districts as $districtData) {
                if ($this->findSeedDistrict($city, $districtData) === null) {
                    $city->districts()->create($districtData);
                }
            }
        }

        $this->backfillAddressCities($seededCities);
    }

    /**
     * Seed identity intentionally accepts any still-canonical stable field.
     * This prevents a repeated production seed from recreating a directory row
     * after an administrator has edited its alias, description or display name.
     * Existing values are never overwritten by the seed.
     *
     * @param array{name: string, alias: string, short_name: string|null, description: string|null} $cityData
     */
    private function findSeedCity(array $cityData): ?City
    {
        return City::query()
            ->where(function (Builder $query) use ($cityData): void {
                $query
                    ->where('alias', $cityData['alias'])
                    ->orWhere('name', $cityData['name']);

                if ($cityData['short_name'] !== null) {
                    $query->orWhere('short_name', $cityData['short_name']);
                }
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * @param array{name: string, alias: string, short_name: string|null, description: string|null} $districtData
     */
    private function findSeedDistrict(City $city, array $districtData): ?District
    {
        return $city->districts()
            ->where(function (Builder $query) use ($districtData): void {
                $query
                    ->where('alias', $districtData['alias'])
                    ->orWhere('name', $districtData['name']);

                if ($districtData['short_name'] !== null) {
                    $query->orWhere('short_name', $districtData['short_name']);
                }
            })
            ->orderBy('id')
            ->first();
    }

    /** @param array<string, City> $seededCities */
    private function backfillAddressCities(array $seededCities): void
    {
        $moscow = $seededCities['moscow'] ?? null;
        if ($moscow !== null) {
            $this->backfillAddressCity($moscow, [
                'Москва',
                'москва',
                'г. Москва',
                'г. москва',
                'город Москва',
                'город москва',
                'Moscow',
                'moscow',
            ]);
        }

        $khimki = $seededCities['khimki'] ?? null;
        if ($khimki !== null) {
            $this->backfillAddressCity($khimki, [
                'Химки',
                'химки',
                'г. Химки',
                'г. химки',
                'город Химки',
                'город химки',
                'Khimki',
                'khimki',
            ]);
        }
    }

    /**
     * @param  array<int, string>  $names
     */
    private function backfillAddressCity(City $city, array $names): void
    {
        $placeholders = implode(', ', array_fill(0, count($names), '?'));

        Address::query()
            ->whereNull('city_id')
            // SQLite LOWER() is ASCII-only by default, so Cyrillic case folding
            // would make the CI backfill diverge from PostgreSQL. Match the
            // explicit known variants and keep only portable TRIM() in SQL.
            ->whereRaw("TRIM(city) IN ({$placeholders})", $names)
            ->update(['city_id' => $city->id]);
    }

    /**
     * @return array<int, array{name: string, alias: string, short_name: string|null, description: string|null, districts: array<int, array{name: string, alias: string, short_name: string|null, description: string|null}>}>
     */
    private function cities(): array
    {
        return [
            [
                'name' => 'Москва',
                'alias' => 'moscow',
                'short_name' => 'Мск',
                'description' => 'Москва — столица России и основной город присутствия MSKBA.',
                'districts' => [
                    [
                        'name' => 'Центральный административный округ',
                        'alias' => 'cao',
                        'short_name' => 'ЦАО',
                        'description' => 'Центр Москвы, исторические районы и территория вокруг Кремля.',
                    ],
                    [
                        'name' => 'Северный административный округ',
                        'alias' => 'sao',
                        'short_name' => 'САО',
                        'description' => 'Северный административный округ Москвы.',
                    ],
                    [
                        'name' => 'Северо-Восточный административный округ',
                        'alias' => 'svao',
                        'short_name' => 'СВАО',
                        'description' => 'Северо-Восточный административный округ Москвы.',
                    ],
                    [
                        'name' => 'Восточный административный округ',
                        'alias' => 'vao',
                        'short_name' => 'ВАО',
                        'description' => 'Восточный административный округ Москвы.',
                    ],
                    [
                        'name' => 'Юго-Восточный административный округ',
                        'alias' => 'yuvao',
                        'short_name' => 'ЮВАО',
                        'description' => 'Юго-Восточный административный округ Москвы.',
                    ],
                    [
                        'name' => 'Южный административный округ',
                        'alias' => 'yuao',
                        'short_name' => 'ЮАО',
                        'description' => 'Южный административный округ Москвы.',
                    ],
                    [
                        'name' => 'Юго-Западный административный округ',
                        'alias' => 'yuzao',
                        'short_name' => 'ЮЗАО',
                        'description' => 'Юго-Западный административный округ Москвы.',
                    ],
                    [
                        'name' => 'Западный административный округ',
                        'alias' => 'zao',
                        'short_name' => 'ЗАО',
                        'description' => 'Западный административный округ Москвы.',
                    ],
                    [
                        'name' => 'Северо-Западный административный округ',
                        'alias' => 'szao',
                        'short_name' => 'СЗАО',
                        'description' => 'Северо-Западный административный округ Москвы.',
                    ],
                    [
                        'name' => 'Зеленоградский административный округ',
                        'alias' => 'zelao',
                        'short_name' => 'ЗелАО',
                        'description' => 'Зеленоградский административный округ Москвы, расположенный за пределами МКАД.',
                    ],
                    [
                        'name' => 'Новомосковский административный округ',
                        'alias' => 'nao',
                        'short_name' => 'НАО',
                        'description' => 'Новомосковский административный округ Новой Москвы.',
                    ],
                    [
                        'name' => 'Троицкий административный округ',
                        'alias' => 'tao',
                        'short_name' => 'ТАО',
                        'description' => 'Троицкий административный округ Новой Москвы.',
                    ],
                ],
            ],
            [
                'name' => 'Химки',
                'alias' => 'khimki',
                'short_name' => 'Химки',
                'description' => 'Город Московской области, непосредственно примыкающий к Москве.',
                'districts' => [
                    [
                        'name' => 'Старые Химки',
                        'alias' => 'starye-khimki',
                        'short_name' => null,
                        'description' => 'Исторический центр города с развитой инфраструктурой, зелёными зонами и станцией МЦД-3.',
                    ],
                    [
                        'name' => 'Новые Химки',
                        'alias' => 'novye-khimki',
                        'short_name' => null,
                        'description' => 'Густонаселённый современный район с многоэтажной застройкой и крупными торговыми центрами.',
                    ],
                    [
                        'name' => 'Новокуркино',
                        'alias' => 'novokurkino',
                        'short_name' => null,
                        'description' => 'Крупный спальный район с современной застройкой, примыкающий к Новым Химкам.',
                    ],
                    [
                        'name' => 'Левобережный',
                        'alias' => 'levoberezhny',
                        'short_name' => null,
                        'description' => 'Обособленный зелёный район на левом берегу канала имени Москвы, рядом с МКАД и МЦД-3 «Беломорская» / «Ховрино».',
                    ],
                    [
                        'name' => 'Клязьма-Старбеево',
                        'alias' => 'klyazma-starbeevo',
                        'short_name' => null,
                        'description' => 'Обширная территория, включающая кварталы Международный, Ивакино и Старбеево.',
                    ],
                    [
                        'name' => 'Сходня',
                        'alias' => 'skhodnya',
                        'short_name' => null,
                        'description' => 'Бывший город, ныне удалённый микрорайон Химок со своей железнодорожной станцией и частным сектором.',
                    ],
                    [
                        'name' => 'Подрезково',
                        'alias' => 'podrezkovo',
                        'short_name' => null,
                        'description' => 'Удалённый микрорайон на северо-западе Химок по железнодорожному направлению.',
                    ],
                    [
                        'name' => 'Новогорск',
                        'alias' => 'novogorsk',
                        'short_name' => null,
                        'description' => 'Экологически чистый район, известный спортивными комплексами и природными парками.',
                    ],
                    [
                        'name' => 'Другие территории',
                        'alias' => 'other-territories',
                        'short_name' => null,
                        'description' => 'Фирсановка, Лобаново, Планерная, а также посёлок аэропорта Шереметьево и другие территории городского округа.',
                    ],
                ],
            ],
        ];
    }
}
