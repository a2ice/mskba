<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class VenueTypeFilter
{
    public static function rules(mixed $value): array
    {
        return is_array($value)
            ? ['type' => ['array', 'list', 'max:'.count(VenueTypeEnum::cases())], 'type.*' => ['required', 'string', Rule::enum(VenueTypeEnum::class)]]
            : ['type' => ['nullable', 'string', Rule::enum(VenueTypeEnum::class)]];
    }

    /** @return list<string> */
    public static function values(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $values = is_array($value) ? $value : [$value];
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('Invalid venue type list.');
        }
        foreach ($values as $type) {
            if (! is_string($type) || VenueTypeEnum::tryFrom($type) === null) {
                throw new InvalidArgumentException('Invalid venue type.');
            }
        }
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    /** Keep the old single-value representation and existing SEO signatures. */
    public static function canonical(mixed $value): string|array|null
    {
        $values = self::values($value);

        return match (count($values)) {
            0 => null,
            1 => $values[0],
            default => $values,
        };
    }
}
