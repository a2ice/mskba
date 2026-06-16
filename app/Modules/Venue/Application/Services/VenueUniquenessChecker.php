<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Location\Domain\Models\Address;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Str;

final class VenueUniquenessChecker
{
    public function aliasForName(string $name): string
    {
        $alias = Str::slug($name);

        return $alias === '' ? 'venue' : $alias;
    }

    public function aliasExistsForName(string $name): bool
    {
        return $this->aliasExists($this->aliasForName($name));
    }

    public function aliasExists(string $alias): bool
    {
        return Venue::query()
            ->whereRaw('LOWER(alias) = ?', [Str::lower($alias)])
            ->exists();
    }

    public function addressExists(
        ?string $rawAddress,
        ?string $city = null,
        ?string $street = null,
        ?string $building = null,
    ): bool {
        $normalizedRawAddress = $this->normalizeAddress($rawAddress);

        if ($normalizedRawAddress !== null && $this->rawAddressExists($normalizedRawAddress)) {
            return true;
        }

        return $this->structuredAddressExists($city, $street, $building);
    }

    private function rawAddressExists(string $normalizedAddress): bool
    {
        if (Venue::query()
            ->whereNotNull('raw_address')
            ->pluck('raw_address')
            ->contains(fn (?string $address): bool => $this->normalizeAddress($address) === $normalizedAddress)) {
            return true;
        }

        return Address::query()
            ->whereNotNull('full_address')
            ->pluck('full_address')
            ->contains(fn (?string $address): bool => $this->normalizeAddress($address) === $normalizedAddress);
    }

    private function structuredAddressExists(?string $city, ?string $street, ?string $building): bool
    {
        $normalizedCity = $this->normalizeAddress($city);
        $normalizedStreet = $this->normalizeAddress($street);
        $normalizedBuilding = $this->normalizeAddress($building);

        if ($normalizedCity === null || $normalizedStreet === null || $normalizedBuilding === null) {
            return false;
        }

        return Address::query()
            ->whereNotNull('city')
            ->whereNotNull('street')
            ->whereNotNull('building')
            ->get(['city', 'street', 'building'])
            ->contains(fn (Address $address): bool => $this->normalizeAddress($address->city) === $normalizedCity
                && $this->normalizeAddress($address->street) === $normalizedStreet
                && $this->normalizeAddress($address->building) === $normalizedBuilding);
    }

    private function normalizeAddress(?string $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($address));

        return $normalized === '' || $normalized === null
            ? null
            : Str::lower($normalized);
    }
}
