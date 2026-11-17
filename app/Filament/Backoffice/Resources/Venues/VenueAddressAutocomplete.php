<?php

namespace App\Filament\Backoffice\Resources\Venues;

use App\Modules\Location\Application\Services\AddressSuggestService;

/**
 * Adapter for Filament's searchable Select. Yandex is called through the
 * existing server-side provider: API credentials never reach the browser.
 *
 * Selection tokens are transient UI state, not persisted or trusted as an
 * authorization decision. Every resulting field is still subject to the
 * usual venue validation and duplicate checks when saved.
 */
final class VenueAddressAutocomplete
{
    public function __construct(private readonly AddressSuggestService $suggestions) {}

    /** @return array<string, string> */
    public function search(string $query): array
    {
        if (mb_strlen(trim($query)) < 3) {
            return [];
        }

        try {
            $suggestions = $this->suggestions->suggest($query);
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }

        $options = [];
        foreach ($suggestions as $suggestion) {
            if (empty($suggestion['has_house'])) {
                continue;
            }

            $fullAddress = self::fullAddress($suggestion);

            if ($fullAddress === '') {
                continue;
            }

            $token = self::encode(['address' => $fullAddress, 'suggestion' => $suggestion]);
            $options[$token] = $fullAddress;
        }

        return $options;
    }

    public static function existing(?string $address): ?string
    {
        $address = trim((string) $address);

        return $address === '' ? null : self::encode(['address' => $address]);
    }

    public static function label(?string $token): ?string
    {
        $value = self::decode($token);

        return isset($value['address']) && is_string($value['address'])
            ? $value['address']
            : null;
    }

    /**
     * Only Yandex suggestion tokens are applied automatically. A previously
     * saved token shows the existing value without modifying any other field.
     *
     * @return array<string, mixed>
     */
    public static function fields(?string $token): array
    {
        $value = self::decode($token);
        $suggestion = $value['suggestion'] ?? null;
        $full = $value['address'] ?? null;

        if (! is_array($suggestion) || ! is_string($full) || $full === '') {
            return [];
        }

        $street = trim((string) ($suggestion['street'] ?? ''));
        $building = trim((string) ($suggestion['building'] ?? ''));

        return [
            'location_full_address' => $full,
            'raw_address' => implode(', ', array_filter(
                [$street, $building], static fn (string $part): bool => $part !== '',
            )),
            'location_city' => $suggestion['city'] ?? null,
            'location_city_id' => $suggestion['city_id'] ?? null,
            'location_district_id' => $suggestion['district_id'] ?? null,
            'location_street' => $suggestion['street'] ?? null,
            'location_building' => $suggestion['building'] ?? null,
            'location_postal_code' => $suggestion['postal_code'] ?? null,
            'location_latitude' => $suggestion['latitude'] ?? null,
            'location_longitude' => $suggestion['longitude'] ?? null,
            'location_metro_ids' => array_values($suggestion['metro_station_ids'] ?? []),
        ];
    }

    /** @param array<string, mixed> $suggestion */
    public static function fullAddress(array $suggestion): string
    {
        $parts = [
            $suggestion['postal_code'] ?? null,
            $suggestion['country'] ?? null,
            $suggestion['city'] ?? null,
            $suggestion['street'] ?? null,
            $suggestion['building'] ?? null,
        ];

        // The provider can omit a country or postal code; the resulting
        // address must still be usable in the editor.
        return implode(', ', array_filter(array_map(
            static fn (mixed $part): string => trim((string) $part),
            $parts,
        ), static fn (string $part): bool => $part !== ''));
    }

    /** @param array<string, mixed> $payload */
    private static function encode(array $payload): string
    {
        return 'address:'.rtrim(strtr(base64_encode(json_encode(
            $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private static function decode(?string $token): array
    {
        if (! is_string($token) || ! str_starts_with($token, 'address:')) {
            return [];
        }

        $decoded = base64_decode(strtr(substr($token, 8), '-_', '+/'), true);
        if (! is_string($decoded)) {
            return [];
        }

        try {
            $value = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($value) ? $value : [];
    }
}
