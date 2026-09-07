<?php

namespace App\Modules\Location\Application\Services;

use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;

final class GeographyResolver
{
    /** @var EloquentCollection<int, City>|null */
    private ?EloquentCollection $cities = null;

    /**
     * @param  array<int, string>  $administrativeAreaNames
     * @return array{city: City|null, district: District|null}
     */
    public function resolve(?string $cityName, array $administrativeAreaNames = []): array
    {
        $city = $this->resolveCity($cityName);
        $district = $city === null
            ? null
            : $this->resolveDistrictForCity($city, $administrativeAreaNames);

        if ($city === null && $administrativeAreaNames !== []) {
            $districtMatches = collect();

            foreach ($this->cities() as $candidateCity) {
                $candidateDistrict = $this->resolveDistrictForCity($candidateCity, $administrativeAreaNames);

                if ($candidateDistrict !== null) {
                    $districtMatches->push($candidateDistrict);
                }
            }

            $districtMatches = $districtMatches->unique('id')->values();

            if ($districtMatches->count() === 1) {
                $district = $districtMatches->first();
                $city = $district?->city;
            }
        }

        return [
            'city' => $city,
            'district' => $district,
        ];
    }

    public function resolveCity(?string $cityName): ?City
    {
        $needle = $this->normalizeCityName($cityName);

        if ($needle === '') {
            return null;
        }

        return $this->cities()->first(function (City $city) use ($needle): bool {
            foreach ([$city->name, $city->short_name, $city->alias] as $candidate) {
                if ($this->normalizeCityName($candidate) === $needle) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @param  array<int, string>  $administrativeAreaNames
     */
    private function resolveDistrictForCity(City $city, array $administrativeAreaNames): ?District
    {
        foreach ($administrativeAreaNames as $areaName) {
            $needle = $this->normalizeDistrictName($areaName);

            if ($needle === '') {
                continue;
            }

            $match = $city->districts->first(function (District $district) use ($needle): bool {
                foreach ([$district->name, $district->short_name, $district->alias] as $candidate) {
                    if ($this->normalizeDistrictName($candidate) === $needle) {
                        return true;
                    }
                }

                return false;
            });

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /** @return EloquentCollection<int, City> */
    private function cities(): EloquentCollection
    {
        return $this->cities ??= City::query()
            ->with('districts')
            ->orderBy('id')
            ->get();
    }

    private function normalizeCityName(?string $value): string
    {
        $normalized = $this->normalizeBase($value);

        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace([
            'город федерального значения',
            'городской округ',
        ], ' ', $normalized);
        $normalized = preg_replace('/\b(город|г)\b/u', ' ', $normalized) ?? '';

        return $this->collapseSpaces($normalized);
    }

    private function normalizeDistrictName(?string $value): string
    {
        $normalized = $this->normalizeBase($value);

        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace([
            'административный округ',
            'городской округ',
            'муниципальный округ',
            'муниципальный район',
            'городской район',
            'территориальное управление',
            'микрорайон',
        ], ' ', $normalized);
        $normalized = preg_replace('/\b(район|округ|мкр)\b/u', ' ', $normalized) ?? '';

        return $this->collapseSpaces($normalized);
    }

    private function normalizeBase(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $normalized = Str::lower(trim($value));
        $normalized = str_replace('ё', 'е', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? '';

        return $this->collapseSpaces($normalized);
    }

    private function collapseSpaces(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    }
}
