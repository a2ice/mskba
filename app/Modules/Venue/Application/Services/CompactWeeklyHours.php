<?php

namespace App\Modules\Venue\Application\Services;

/**
 * Display-only projection of effective weekly hours; exceptions stay separate.
 */
final class CompactWeeklyHours
{
    private const WEEKDAYS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

    /** @return list<array{label: string, hours: string}> */
    public static function rows(array $weeklyHours): array
    {
        $byDay = [];
        foreach ($weeklyHours as $day) {
            $byDay[$day['weekday']] = $day['intervals'] ?? null;
        }

        $groups = [];
        foreach (self::WEEKDAYS as $index => $weekday) {
            $hours = self::intervalLabel($byDay[$weekday] ?? null);
            $groups[$hours][] = $index;
        }

        if (count($groups) === 1) {
            return [['label' => '', 'hours' => array_key_first($groups)]];
        }

        $weekendSplit = count($groups) === 2
            && in_array([0, 1, 2, 3, 4], $groups, true)
            && in_array([5, 6], $groups, true);

        $rows = [];
        foreach ($groups as $hours => $indexes) {
            $label = $weekendSplit
                ? ($indexes[0] === 0 ? 'Будни' : 'Выходные')
                : self::dayRanges($indexes);
            $rows[] = ['label' => $label, 'hours' => $hours];
        }

        return $rows;
    }

    public static function intervalLabel(?array $intervals): string
    {
        if ($intervals === null) {
            return 'Режим работы не указан';
        }
        if ($intervals === []) {
            return 'Не работает';
        }

        return implode(', ', array_map(static fn (array $interval): string =>
            $interval['startsAt'].'–'.($interval['endsAt'] === '24:00' ? '00:00' : $interval['endsAt']),
            $intervals));
    }

    private static function dayRanges(array $indexes): string
    {
        $ranges = [];
        $first = $previous = array_shift($indexes);
        foreach ($indexes as $index) {
            if ($index !== $previous + 1) {
                $ranges[] = self::rangeLabel($first, $previous);
                $first = $index;
            }
            $previous = $index;
        }
        $ranges[] = self::rangeLabel($first, $previous);

        return implode(', ', $ranges);
    }

    private static function rangeLabel(int $first, int $last): string
    {
        return self::WEEKDAYS[$first].($first === $last ? '' : '–'.self::WEEKDAYS[$last]);
    }
}
