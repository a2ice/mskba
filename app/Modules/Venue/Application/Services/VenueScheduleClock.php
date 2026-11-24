<?php

namespace App\Modules\Venue\Application\Services;

/**
 * Opening hours always belong to one local calendar day.
 *
 * HTML time inputs cannot represent 24:00. Exactly 00:00 as the END of an
 * interval therefore means the exclusive midnight boundary at minute 1440.
 * As a START, 00:00 still means the beginning of that day.
 *
 * Database time columns retain the UI-safe 00:00 marker; time comparisons,
 * overlap checks and projections use the exclusive 24:00 boundary.
 */
final class VenueScheduleClock
{
    public static function end(string $time): string
    {
        $clock = substr($time, 0, 5);

        return $clock === '00:00' ? '24:00' : $clock;
    }

    public static function isOrdered(string $start, string $end): bool
    {
        return substr($start, 0, 5) < self::end($end);
    }
}
