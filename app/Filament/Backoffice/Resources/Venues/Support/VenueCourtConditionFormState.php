<?php

namespace App\Filament\Backoffice\Resources\Venues\Support;

use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;

/**
 * A Filament select should edit a tri-state value without relying on implicit
 * boolean/Enum component dehydration (including the primary court record).
 */
final class VenueCourtConditionFormState
{
    private const BOOLEAN_FIELDS = [
        'requires_payment',
        'requires_booking',
        'requires_booking_approval',
        'rental_enabled',
    ];

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    public static function forForm(array $data): array
    {
        foreach (self::BOOLEAN_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $data[$field] = match ($data[$field]) {
                null, 'inherit' => 'inherit',
                false, 0, '0', 'no' => 'no',
                true, 1, '1', 'yes' => 'yes',
                default => throw new \InvalidArgumentException("Некорректное значение {$field}."),
            };
        }

        if (array_key_exists('operational_status', $data)) {
            $value = $data['operational_status'];
            $data['operational_status'] = $value instanceof VenueOperationalStatusEnum
                ? $value->value : ($value ?: 'inherit');
        }

        return $data;
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    public static function forDatabase(array $data): array
    {
        foreach (self::BOOLEAN_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $data[$field] = match ($data[$field]) {
                null, '', 'inherit' => null,
                false, 0, '0', 'no' => false,
                true, 1, '1', 'yes' => true,
                default => throw new \InvalidArgumentException("Некорректное значение {$field}."),
            };
        }

        if (array_key_exists('operational_status', $data)) {
            $data['operational_status'] = match ($data['operational_status']) {
                null, '', 'inherit' => null,
                default => $data['operational_status'] instanceof VenueOperationalStatusEnum
                    ? $data['operational_status']->value
                    : $data['operational_status'],
            };
        }

        return $data;
    }
}
