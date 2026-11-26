<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Application\Services\VenueTypeFilter;
use InvalidArgumentException;

/** Exact identity of an editorial catalogue subset, not presentation state. */
final class VenueCatalogLandingCriteria
{
    public const KEYS = ['city_id', 'district_id', 'type', 'operational_status', 'access', 'rental_configured'];

    /** @return array<string, int|string|array> */
    public static function normalize(array $filters): array
    {
        if (array_diff(array_keys($filters), self::KEYS) !== []) {
            throw new InvalidArgumentException('Unsupported editorial filter.');
        }

        $normalized = [];
        foreach (self::KEYS as $key) {
            $value = $filters[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($key, ['city_id', 'district_id'], true)) {
                if ((! is_int($value) && ! is_string($value)) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    throw new InvalidArgumentException('Invalid geography identifier.');
                }
                $normalized[$key] = (int) $value;
                continue;
            }

            if ($key === 'type') {
                $type = VenueTypeFilter::canonical($value);
                if ($type !== null) {
                    $normalized[$key] = $type;
                }
                continue;
            }

            if ($key === 'rental_configured') {
                if (in_array($value, [true, 1, '1'], true)) {
                    $normalized[$key] = 1;
                } elseif (! in_array($value, [false, 0, '0'], true)) {
                    throw new InvalidArgumentException('Invalid rental filter.');
                }
                continue;
            }

            if (! is_string($value) || ! match ($key) {
                'operational_status' => VenueOperationalStatusEnum::tryFrom($value) !== null,
                'access' => in_array($value, ['free', 'paid', 'approval'], true),
                default => false,
            }) {
                throw new InvalidArgumentException('Invalid editorial filter value.');
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** Only use after the ordinary catalogue request has been validated. */
    public static function fromCatalog(array $filters): ?array
    {
        // Presentation parameters do not define a new editorial subset.
        foreach (['sort', 'view', 'page', 'fragment'] as $key) {
            unset($filters[$key]);
        }

        foreach (['has_upcoming_events', 'my_interests'] as $key) {
            if (! in_array($filters[$key] ?? null, [null, '', false, 0, '0'], true)) {
                return null;
            }
            unset($filters[$key]);
        }

        // event_days alone is inert when the events filter is off.
        unset($filters['event_days']);

        foreach (['search', 'interest_id', 'latitude', 'longitude', 'radius_km'] as $key) {
            if (($filters[$key] ?? null) !== null && $filters[$key] !== '') {
                return null;
            }
            unset($filters[$key]);
        }

        try {
            return self::normalize($filters);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function signature(array $filters): string
    {
        return hash('sha256', json_encode(self::normalize($filters), JSON_THROW_ON_ERROR));
    }
}
