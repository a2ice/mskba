<?php

namespace App\Modules\Location\Application\Services;

use App\Modules\Location\Application\Contracts\AddressSuggestProvider;
use App\Modules\Location\Application\DTO\AddressSuggestionDTO;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Location\Infrastructure\Yandex\YandexAddressSuggestProvider;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;

final class AddressSuggestService
{
    public function __construct(
        private readonly YandexAddressSuggestProvider $yandexProvider,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function suggest(string $query): array
    {
        $provider = $this->resolveProvider();
        $suggestions = $provider->suggest(trim($query));

        $result = [];

        foreach ($suggestions as $suggestion) {
            $mapped = $this->mapSuggestion($suggestion);

            if ($mapped !== null) {
                $result[] = $mapped;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reverse(float $latitude, float $longitude): ?array
    {
        $suggestion = $this->resolveProvider()->reverse($latitude, $longitude);

        return $suggestion === null ? null : $this->mapSuggestion($suggestion);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapSuggestion(AddressSuggestionDTO $suggestion): ?array
    {
        $defaultCountry = trim((string) config('integrations.address.default_country', ''));

        if (! $this->countryAllowed($suggestion, $defaultCountry)) {
            return null;
        }

        [$metroStationIds, $metroStationLabels] = $this->matchMetroStations($suggestion);

        return [
            'label' => $suggestion->label,
            'country' => $suggestion->country,
            'city' => $suggestion->city,
            'street' => $suggestion->street,
            'building' => $suggestion->building,
            'postal_code' => $suggestion->postalCode,
            'latitude' => $suggestion->latitude,
            'longitude' => $suggestion->longitude,
            'has_house' => $suggestion->hasHouse(),
            'metro_station_ids' => $metroStationIds,
            'metro_station_labels' => $metroStationLabels,
        ];
    }

    private function resolveProvider(): AddressSuggestProvider
    {
        return match (config('integrations.address.provider', 'yandex')) {
            'yandex' => $this->yandexProvider,
            default => $this->yandexProvider,
        };
    }

    private function countryAllowed(AddressSuggestionDTO $suggestion, string $defaultCountry): bool
    {
        if ($defaultCountry === '' || $suggestion->country === null) {
            return true;
        }

        return mb_stripos($suggestion->country, $defaultCountry, 0, 'UTF-8') !== false;
    }

    /**
     * @return array{0: array<int>, 1: array<int, string>}
     */
    private function matchMetroStations(AddressSuggestionDTO $suggestion): array
    {
        $stations = $this->metroCandidates();

        if ($stations->isEmpty()) {
            return [[], []];
        }

        $matched = [];

        foreach ($suggestion->metroNames as $metroName) {
            $needle = $this->normalizeMetroName($metroName);

            if ($needle === '') {
                continue;
            }

            $exactMatches = $stations->filter(
                fn (MetroStation $station): bool => $this->normalizeMetroName($station->name) === $needle
            );

            foreach ($exactMatches as $station) {
                $matched[$station->id] = $station;
            }

            if ($exactMatches->isNotEmpty()) {
                continue;
            }

            $partialMatches = $stations->filter(function (MetroStation $station) use ($needle): bool {
                $normalized = $this->normalizeMetroName($station->name);

                return $normalized !== ''
                    && (str_contains($normalized, $needle) || str_contains($needle, $normalized));
            });

            foreach ($partialMatches as $station) {
                $matched[$station->id] = $station;
            }
        }

        if ($matched === [] && $suggestion->latitude !== null && $suggestion->longitude !== null) {
            $nearest = $this->resolveNearestMetroStation($stations, $suggestion->latitude, $suggestion->longitude);

            if ($nearest !== null) {
                $matched[$nearest->id] = $nearest;
            }
        }

        if ($matched === []) {
            return [[], []];
        }

        return [
            array_map(fn (MetroStation $station): int => (int) $station->id, array_values($matched)),
            array_map(fn (MetroStation $station): string => $this->metroLabel($station), array_values($matched)),
        ];
    }

    /**
     * @return EloquentCollection<int, MetroStation>
     */
    private function metroCandidates(): EloquentCollection
    {
        return MetroStation::query()
            ->with('line')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, MetroStation>  $stations
     */
    private function resolveNearestMetroStation(EloquentCollection $stations, float $latitude, float $longitude): ?MetroStation
    {
        $nearest = null;
        $nearestDistance = null;

        foreach ($stations as $station) {
            if ($station->latitude === null || $station->longitude === null) {
                continue;
            }

            $distance = $this->distanceSquared($latitude, $longitude, (float) $station->latitude, (float) $station->longitude);

            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearest = $station;
            }
        }

        return $nearest;
    }

    private function distanceSquared(float $latA, float $lonA, float $latB, float $lonB): float
    {
        $dLat = $latA - $latB;
        $dLon = $lonA - $lonB;

        return ($dLat * $dLat) + ($dLon * $dLon);
    }

    private function normalizeMetroName(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $normalized = Str::lower(trim($value));
        $normalized = str_replace(['м.', 'м ', 'метро', 'станция', 'ст.'], '', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? '';
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? '';

        return $normalized;
    }

    private function metroLabel(MetroStation $station): string
    {
        return $station->line?->name
            ? "{$station->name} ({$station->line->name})"
            : $station->name;
    }
}
