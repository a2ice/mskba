<?php

namespace App\Modules\Location\Infrastructure\Yandex;

use App\Modules\Location\Application\Contracts\AddressSuggestProvider;
use App\Modules\Location\Application\DTO\AddressSuggestionDTO;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

final class YandexAddressSuggestProvider implements AddressSuggestProvider
{
    public function suggest(string $query): array
    {
        if ($this->apiKey() === null) {
            return [];
        }

        $suggestions = $this->fetchSuggestSuggestions($query);

        if ($suggestions === []) {
            $suggestions = $this->fetchGeocodeSuggestions($query, null);
        }

        $houseNumber = $this->extractHouseNumber($query);
        $fallbackQuery = $this->stripHouseFromQuery($query);

        if ($houseNumber !== null && $fallbackQuery !== '' && $fallbackQuery !== $query) {
            $suggestions = $this->mergeSuggestions(
                $suggestions,
                $this->fetchGeocodeSuggestions($fallbackQuery, $houseNumber),
            );
        }

        return array_slice($suggestions, 0, $this->limit());
    }

    /**
     * @return array<int, AddressSuggestionDTO>
     */
    private function fetchSuggestSuggestions(string $query): array
    {
        try {
            $response = Http::timeout(5)->get('https://suggest-maps.yandex.ru/v1/suggest', [
                'apikey' => $this->apiKey(),
                'text' => $query,
                'lang' => 'ru_RU',
                'types' => 'geo',
                'results' => $this->limit(),
            ]);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->ok()) {
            return [];
        }

        $results = Arr::get($response->json(), 'results', []);

        if (! is_array($results)) {
            return [];
        }

        $suggestions = [];

        foreach ($results as $item) {
            if (! is_array($item)) {
                continue;
            }

            $text = $this->resolveSuggestText($item);

            if ($text === null) {
                continue;
            }

            $suggestions = $this->mergeSuggestions(
                $suggestions,
                $this->fetchGeocodeSuggestions($text, null),
            );

            if (count($suggestions) >= $this->limit()) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * @return array<int, AddressSuggestionDTO>
     */
    private function fetchGeocodeSuggestions(string $query, ?string $houseNumber): array
    {
        try {
            $response = Http::timeout(5)->get('https://geocode-maps.yandex.ru/1.x/', [
                'apikey' => $this->apiKey(),
                'geocode' => $query,
                'format' => 'json',
                'lang' => 'ru_RU',
                'results' => 10,
                'kind' => 'house',
            ]);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->ok()) {
            return [];
        }

        $members = Arr::get($response->json(), 'response.GeoObjectCollection.featureMember', []);

        if (! is_array($members)) {
            return [];
        }

        $suggestions = [];

        foreach ($members as $member) {
            if (! is_array($member)) {
                continue;
            }

            $suggestion = $this->suggestionFromGeocodeMember($member, $houseNumber);

            if ($suggestion !== null) {
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }

    private function suggestionFromGeocodeMember(array $member, ?string $houseNumber): ?AddressSuggestionDTO
    {
        $geo = Arr::get($member, 'GeoObject', []);
        $meta = Arr::get($geo, 'metaDataProperty.GeocoderMetaData', []);
        $address = Arr::get($meta, 'Address', []);
        $components = Arr::get($address, 'Components', []);

        if (! is_array($geo) || ! is_array($meta) || ! is_array($address) || ! is_array($components)) {
            return null;
        }

        $label = (string) Arr::get($meta, 'text', '');
        $country = $this->findComponent($components, ['country']);
        $city = $this->findComponent($components, ['locality'])
            ?? $this->findComponent($components, ['province', 'area']);
        $street = $this->findComponent($components, ['street']);
        $building = $this->findComponent($components, ['house']);

        if ($city === null || $street === null || $building === null) {
            return null;
        }

        if ($houseNumber !== null && ! str_starts_with($building, $houseNumber)) {
            return null;
        }

        [$longitude, $latitude] = $this->extractCoordinates($geo);

        return new AddressSuggestionDTO(
            label: $label !== '' ? $label : trim("{$city}, {$street}, {$building}"),
            country: $country,
            city: $city,
            street: $street,
            building: $building,
            postalCode: $this->nullableString(Arr::get($address, 'postal_code')),
            latitude: $latitude,
            longitude: $longitude,
            metroNames: $this->findComponents($components, ['metro']),
        );
    }

    private function resolveSuggestText(array $item): ?string
    {
        $text = Arr::get($item, 'text');

        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }

        $title = Arr::get($item, 'title.text');
        $subtitle = Arr::get($item, 'subtitle.text');

        if (is_string($title) && is_string($subtitle)) {
            return trim("{$title}, {$subtitle}");
        }

        return is_string($title) && trim($title) !== ''
            ? trim($title)
            : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @param  array<int, string>  $kinds
     */
    private function findComponent(array $components, array $kinds): ?string
    {
        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $kind = $component['kind'] ?? null;
            $name = $component['name'] ?? null;

            if (is_string($kind) && is_string($name) && in_array($kind, $kinds, true)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @param  array<int, string>  $kinds
     * @return array<int, string>
     */
    private function findComponents(array $components, array $kinds): array
    {
        $result = [];

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $kind = $component['kind'] ?? null;
            $name = $component['name'] ?? null;

            if (is_string($kind) && is_string($name) && in_array($kind, $kinds, true)) {
                $result[] = $name;
            }
        }

        return $result;
    }

    /**
     * @return array{0: float|null, 1: float|null}
     */
    private function extractCoordinates(array $geo): array
    {
        $point = Arr::get($geo, 'Point.pos');

        if (! is_string($point) || trim($point) === '') {
            return [null, null];
        }

        [$longitude, $latitude] = array_pad(explode(' ', trim($point)), 2, null);

        if ($longitude === null || $latitude === null || ! is_numeric($longitude) || ! is_numeric($latitude)) {
            return [null, null];
        }

        return [(float) $longitude, (float) $latitude];
    }

    private function extractHouseNumber(string $query): ?string
    {
        preg_match('/\b(\d+)\b/u', $query, $matches);

        return isset($matches[1]) ? (string) $matches[1] : null;
    }

    private function stripHouseFromQuery(string $query): string
    {
        return trim(preg_replace('/[,\s]+\d+\S*$/u', '', $query) ?? '');
    }

    /**
     * @param  array<int, AddressSuggestionDTO>  $base
     * @param  array<int, AddressSuggestionDTO>  $extra
     * @return array<int, AddressSuggestionDTO>
     */
    private function mergeSuggestions(array $base, array $extra): array
    {
        $seen = [];

        foreach ($base as $item) {
            $seen[mb_strtolower($item->label)] = true;
        }

        foreach ($extra as $item) {
            $key = mb_strtolower($item->label);

            if (! isset($seen[$key])) {
                $base[] = $item;
                $seen[$key] = true;
            }
        }

        return $base;
    }

    private function apiKey(): ?string
    {
        $apiKey = config('integrations.yandex.api_key');

        return is_string($apiKey) && trim($apiKey) !== ''
            ? trim($apiKey)
            : null;
    }

    private function limit(): int
    {
        return max(1, (int) config('integrations.address.suggest_limit', 5));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}
