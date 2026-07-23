<?php

namespace App\Modules\Location\Application\Services;

final class AddressDisplayFormatter
{
    public function format(
        ?string $rawAddress,
        ?string $city = null,
        ?string $street = null,
        ?string $building = null,
    ): ?string {
        $parts = array_values(array_filter(
            [$city, $street, $building],
            fn (?string $part): bool => $part !== null && trim($part) !== '',
        ));

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        $address = trim((string) $rawAddress);

        if ($address === '') {
            return null;
        }

        return preg_replace(
            '/^(?:Россия|Российская Федерация)\s*,\s*/iu',
            '',
            $address,
        ) ?: $address;
    }
}
