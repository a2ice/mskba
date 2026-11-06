<?php

namespace App\Filament\Backoffice\Resources\Venues;

use Illuminate\Support\Collection;

/**
 * UI adapter: one set of default hours plus exceptional weekdays.
 *
 * Persistence remains venue_schedule_intervals (one row per weekday and
 * interval); all public schedules, bookings and dated exceptions keep their
 * existing semantics.
 */
final class VenueWeeklyScheduleForm
{
    /**
     * @param iterable<array|object> $stored
     * @return array{weekly_default_intervals: array, weekday_overrides: array}
     */
    public static function fromStored(iterable $stored): array
    {
        $days = array_fill_keys(range(1, 7), []);

        foreach ($stored as $row) {
            $day = (int) self::value($row, 'day_of_week');
            if ($day < 1 || $day > 7) {
                continue;
            }

            $days[$day][] = [
                'starts_at' => substr((string) self::value($row, 'starts_at'), 0, 5),
                'ends_at' => substr((string) self::value($row, 'ends_at'), 0, 5),
            ];
        }

        foreach ($days as &$intervals) {
            usort($intervals, static fn (array $a, array $b): int =>
                [$a['starts_at'], $a['ends_at']] <=> [$b['starts_at'], $b['ends_at']]);
        }
        unset($intervals);

        // Choose the most common daily schedule as the baseline, avoiding
        // needless override rows for existing venues. Monday wins ties.
        $signatures = [];
        $occurrences = [];
        foreach ($days as $day => $intervals) {
            $signature = json_encode($intervals, JSON_THROW_ON_ERROR);
            $signatures[$day] = $signature;
            $occurrences[$signature] = ($occurrences[$signature] ?? 0) + 1;
        }

        $defaultSignature = $signatures[1];
        foreach (range(2, 7) as $day) {
            if ($occurrences[$signatures[$day]] > $occurrences[$defaultSignature]) {
                $defaultSignature = $signatures[$day];
            }
        }

        $default = json_decode($defaultSignature, true, 512, JSON_THROW_ON_ERROR);
        $overrides = [];
        foreach ($days as $day => $intervals) {
            if ($signatures[$day] === $defaultSignature) {
                continue;
            }
            $overrides[] = [
                'day_of_week' => $day,
                'is_closed' => $intervals === [],
                'intervals' => $intervals,
            ];
        }

        return [
            'weekly_default_intervals' => $default,
            'weekday_overrides' => $overrides,
        ];
    }

    /**
     * @param array $default
     * @param array $overrides
     * @return array<int, array{day_of_week: int, starts_at: string, ends_at: string}>
     */
    public static function toStored(array $default, array $overrides): array
    {
        $days = array_fill_keys(range(1, 7), array_values($default));

        foreach ($overrides as $override) {
            if (! is_array($override)) {
                continue;
            }
            $day = (int) ($override['day_of_week'] ?? 0);
            if ($day < 1 || $day > 7) {
                continue;
            }
            $days[$day] = (bool) ($override['is_closed'] ?? false)
                ? [] : array_values((array) ($override['intervals'] ?? []));
        }

        $rows = [];
        foreach ($days as $day => $intervals) {
            foreach ($intervals as $interval) {
                $rows[] = [
                    'day_of_week' => $day,
                    'starts_at' => (string) ($interval['starts_at'] ?? ''),
                    'ends_at' => (string) ($interval['ends_at'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    private static function value(array|object $row, string $key): mixed
    {
        return is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
    }
}
