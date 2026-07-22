<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Support\Text\CyrillicTransliterator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class VenueUniquenessChecker
{
    public function __construct(
        private readonly CyrillicTransliterator $transliterator,
    ) {}

    public function aliasForName(string $name): string
    {
        $alias = Str::slug($this->transliterator->transliterate($name));

        return $alias === '' ? 'venue' : $alias;
    }

    /**
     * @param  array<int, VenueStatusEnum>  $statuses
     */
    public function aliasExistsForName(string $name, VenueTypeEnum $type, array $statuses = []): bool
    {
        return $this->aliasExists($this->aliasForName($name), $type, $statuses);
    }

    /**
     * @param  array<int, VenueStatusEnum>  $statuses
     */
    public function aliasExists(string $alias, VenueTypeEnum $type, array $statuses = []): bool
    {
        return Venue::query()
            ->whereRaw('LOWER(alias) = ?', [Str::lower($alias)])
            ->where('type', $type)
            ->when($statuses !== [], fn ($query) => $query->whereIn(
                'status',
                array_map(fn (VenueStatusEnum $status): string => $status->value, $statuses),
            ))
            ->exists();
    }

    /**
     * @param  array<int, VenueStatusEnum>  $statuses
     */
    public function aliasExistsForActor(Actor $actor, string $alias, VenueTypeEnum $type, array $statuses = []): bool
    {
        return $this->ownedVenueQuery($actor, $type, $statuses)
            ->whereRaw('LOWER(alias) = ?', [Str::lower($alias)])
            ->exists();
    }

    /**
     * @param  array<int, VenueStatusEnum>  $statuses
     */
    public function addressExists(
        ?string $rawAddress,
        ?string $city = null,
        ?string $street = null,
        ?string $building = null,
        ?VenueTypeEnum $type = null,
        array $statuses = [],
    ): bool {
        $normalizedRawAddress = $this->normalizeAddress($rawAddress);

        if ($normalizedRawAddress !== null && $this->rawAddressExists($normalizedRawAddress, $type, $statuses)) {
            return true;
        }

        return $this->structuredAddressExists($city, $street, $building, $type, $statuses);
    }

    /**
     * @param  array<int, VenueStatusEnum>  $statuses
     */
    public function addressExistsForActor(
        Actor $actor,
        ?string $rawAddress,
        ?string $city = null,
        ?string $street = null,
        ?string $building = null,
        ?VenueTypeEnum $type = null,
        array $statuses = [],
    ): bool {
        $keys = collect([
            $this->normalizeAddress($rawAddress),
            $this->structuredAddressKey($city, $street, $building),
        ])->filter()->values();

        if ($keys->isEmpty()) {
            return false;
        }

        return $this->ownedVenueQuery($actor, $type, $statuses)
            ->get()
            ->contains(function (Venue $venue) use ($keys): bool {
                return array_intersect($keys->all(), $this->duplicateAddressKeysForVenue($venue)) !== [];
            });
    }

    /**
     * @return array<int, string>
     */
    public function duplicateAddressKeysForVenue(Venue $venue): array
    {
        $venue->loadMissing('location.address');

        return collect([
            $this->normalizeAddress($venue->raw_address),
            $this->normalizeAddress($venue->location?->address?->full_address),
            $this->structuredAddressKey(
                $venue->location?->address?->city,
                $venue->location?->address?->street,
                $venue->location?->address?->building,
            ),
        ])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function venuesShareAddress(Venue $first, Venue $second): bool
    {
        $firstKeys = $this->duplicateAddressKeysForVenue($first);
        $secondKeys = $this->duplicateAddressKeysForVenue($second);

        return array_values(array_intersect($firstKeys, $secondKeys)) !== [];
    }

    public function normalizeAddress(?string $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($address));

        return $normalized === '' || $normalized === null
            ? null
            : Str::lower($normalized);
    }

    /**
     * @param  array<int, VenueStatusEnum>  $statuses
     */
    private function rawAddressExists(string $normalizedAddress, ?VenueTypeEnum $type, array $statuses): bool
    {
        return $this->venueAddressQuery($type, $statuses)
            ->get()
            ->contains(function (Venue $venue) use ($normalizedAddress): bool {
                return in_array($normalizedAddress, $this->duplicateAddressKeysForVenue($venue), true);
            });
    }

    /**
     * @param  array<int, VenueStatusEnum>  $statuses
     */
    private function structuredAddressExists(?string $city, ?string $street, ?string $building, ?VenueTypeEnum $type, array $statuses): bool
    {
        $structuredAddressKey = $this->structuredAddressKey($city, $street, $building);
        if ($structuredAddressKey === null) {
            return false;
        }

        return $this->venueAddressQuery($type, $statuses)
            ->get()
            ->contains(function (Venue $venue) use ($structuredAddressKey): bool {
                return in_array($structuredAddressKey, $this->duplicateAddressKeysForVenue($venue), true);
            });
    }

    private function structuredAddressKey(?string $city, ?string $street, ?string $building): ?string
    {
        $normalizedCity = $this->normalizeAddress($city);
        $normalizedStreet = $this->normalizeAddress($street);
        $normalizedBuilding = $this->normalizeAddress($building);

        if ($normalizedCity === null || $normalizedStreet === null || $normalizedBuilding === null) {
            return null;
        }

        return implode('|', [$normalizedCity, $normalizedStreet, $normalizedBuilding]);
    }

    /**
     * @param  array<int, VenueStatusEnum>  $statuses
     * @return Builder<Venue>
     */
    private function venueAddressQuery(?VenueTypeEnum $type, array $statuses): Builder
    {
        return Venue::query()
            ->with('location.address')
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->when($statuses !== [], fn ($query) => $query->whereIn(
                'status',
                array_map(fn (VenueStatusEnum $status): string => $status->value, $statuses),
            ));
    }

    /**
     * @param  array<int, VenueStatusEnum>  $statuses
     * @return Builder<Venue>
     */
    private function ownedVenueQuery(Actor $actor, VenueTypeEnum $type, array $statuses): Builder
    {
        return Venue::query()
            ->with('location.address')
            ->where('type', $type)
            ->whereHas('creatorActor', function (Builder $query) use ($actor): void {
                if ($actor->user_id !== null) {
                    $query->where('user_id', $actor->user_id);

                    return;
                }

                $query->whereRaw('1 = 0');
            })
            ->when($statuses !== [], fn ($query) => $query->whereIn(
                'status',
                array_map(fn (VenueStatusEnum $status): string => $status->value, $statuses),
            ));
    }
}
