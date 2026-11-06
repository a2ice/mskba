<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\Venue\Domain\Models\VenueSchedule;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Explicit, administrator-triggered snapshot from a venue into selected courts.
 *
 * No observers, jobs, listeners or timers call this service. The future
 * auto_sync_enabled setting is intentionally not read by this service.
 * Existing bookings/events are never mutated.
 */
final class SyncVenueCourtSchedule
{
    /**
     * @param array<int, int> $courtIds
     * @return array{courts: int, days: int, prices: int}
     */
    public function handle(
        Venue $venue,
        array $courtIds,
        string $mode,
        ?string $fromDate = null,
        ?string $toDate = null,
        bool $includePrices = true,
    ): array {
        if (! in_array($mode, ['day', 'period', 'full'], true)) {
            throw ValidationException::withMessages(['mode' => 'Выберите режим синхронизации.']);
        }

        $schedule = $venue->schedule()->with(['intervals', 'exceptions.intervals'])->first();
        if (! $schedule instanceof VenueSchedule) {
            throw ValidationException::withMessages(['mode' => 'У площадки ещё не задано расписание.']);
        }

        $zone = (string) ($schedule->timezone ?: 'Europe/Moscow');
        $first = $mode === 'full'
            ? null : $this->date($fromDate ?? CarbonImmutable::now($zone)->toDateString(), $zone);
        $last = match ($mode) {
            'full' => null,
            'day' => $first,
            default => $this->date($toDate ?? $first->addDays(6)->toDateString(), $zone),
        };
        if ($first !== null && $last !== null
            && ($last->lessThan($first) || $first->diffInDays($last) > 89)) {
            throw ValidationException::withMessages([
                'to_date' => 'Период должен быть от 1 до 90 дней. Для всего расписания выберите полную синхронизацию.',
            ]);
        }

        $ids = collect($courtIds)->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)->unique()->values()->all();
        if ($ids === []) {
            throw ValidationException::withMessages(['mode' => 'Не выбраны залы для синхронизации.']);
        }

        $policy = $includePrices
            ? VenueBookingPolicy::query()->where('venue_id', $venue->id)->where('active_marker', true)->first()
            : null;
        if ($includePrices && ($policy === null || (int) $policy->time_step_minutes < 1)) {
            throw ValidationException::withMessages([
                'include_prices' => 'Чтобы копировать цены, сначала опубликуйте условия аренды площадки.',
            ]);
        }

        $weeklyPrices = [];
        $calendarPrices = [];
        if ($includePrices) {
            foreach ($venue->scheduleSlotPrices()->get() as $price) {
                $weeklyPrices[$price->day_of_week.'|'.substr((string) $price->starts_at, 0, 5)] = $price;
            }
            foreach ($venue->calendarSlotPrices()->get() as $price) {
                $calendarPrices[$price->date->format('Y-m-d').'|'.substr((string) $price->starts_at, 0, 5)] = $price;
            }
        }

        $result = DB::transaction(function () use (
            $venue, $ids, $mode, $first, $last, $schedule, $policy,
            $includePrices, $weeklyPrices, $calendarPrices,
        ): array {
            $courts = VenueCourt::query()->where('venue_id', $venue->id)
                ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($courts->count() !== count($ids)) {
                throw ValidationException::withMessages(['mode' => 'Один из выбранных залов не принадлежит площадке.']);
            }

            $days = $mode === 'full' ? 0 : (int) $first->diffInDays($last) + 1;
            $prices = 0;
            foreach ($courts as $court) {
                $this->syncSchedule($schedule, $court, $mode, $first, $last);
                if ($includePrices) {
                    $prices += $this->syncPrices(
                        $court, $policy, $weeklyPrices, $calendarPrices,
                        $mode, $first, $last,
                    );
                }
            }

            return ['courts' => $courts->count(), 'days' => $days, 'prices' => $prices];
        }, 3);

        Log::info('Court schedule synchronized manually from venue', [
            'venue_id' => $venue->id,
            'court_ids' => $ids,
            'mode' => $mode,
            'from' => $first?->toDateString(),
            'to' => $last?->toDateString(),
            'include_prices' => $includePrices,
            'user_id' => auth()->id(),
            'result' => $result,
        ]);

        return $result;
    }

    private function syncSchedule(
        VenueSchedule $source,
        VenueCourt $court,
        string $mode,
        ?CarbonImmutable $first,
        ?CarbonImmutable $last,
    ): void {
        $current = $court->schedule()->first();
        $week = $mode === 'full' || $current === null
            ? $source->intervals->map(fn ($row): array => [
                'day_of_week' => (int) $row->day_of_week,
                'starts_at' => substr((string) $row->starts_at, 0, 5),
                'ends_at' => substr((string) $row->ends_at, 0, 5),
            ])->values()->all()
            : ($current->weekly_intervals ?? []);

        if ($mode === 'full') {
            $exceptions = [];
            foreach ($source->exceptions as $exception) {
                $date = $exception->date->toDateString();
                $intervals = $exception->intervals->values();
                if ($exception->is_closed || $intervals->isEmpty()) {
                    $exceptions[] = ['date' => $date, 'is_closed' => true];
                    continue;
                }
                foreach ($intervals as $interval) {
                    $exceptions[] = [
                        'date' => $date, 'is_closed' => false,
                        'starts_at' => substr((string) $interval->starts_at, 0, 5),
                        'ends_at' => substr((string) $interval->ends_at, 0, 5),
                    ];
                }
            }
        } else {
            $exceptions = array_values(array_filter(
                $current?->exceptions ?? [],
                fn (array $row): bool => ($row['date'] ?? '') < $first->toDateString()
                    || ($row['date'] ?? '') > $last->toDateString(),
            ));
            for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
                $date = $day->toDateString();
                $parentException = $source->exceptions->first(
                    fn ($row): bool => $row->date->toDateString() === $date,
                );
                $intervals = $parentException !== null
                    ? $parentException->intervals
                    : $source->intervals->where('day_of_week', $day->dayOfWeekIso);

                if ($parentException?->is_closed || $intervals->isEmpty()) {
                    $exceptions[] = ['date' => $date, 'is_closed' => true];
                    continue;
                }
                foreach ($intervals as $interval) {
                    $exceptions[] = [
                        'date' => $date, 'is_closed' => false,
                        'starts_at' => substr((string) $interval->starts_at, 0, 5),
                        'ends_at' => substr((string) $interval->ends_at, 0, 5),
                    ];
                }
            }
        }

        $court->schedule()->updateOrCreate(['venue_court_id' => $court->id], [
            'weekly_intervals' => $week,
            'exceptions' => $exceptions,
        ]);
        $court->unsetRelation('schedule');
    }

    /**
     * @param array<string, object> $weekly
     * @param array<string, object> $calendar
     */
    private function syncPrices(
        VenueCourt $court, VenueBookingPolicy $policy,
        array $weekly, array $calendar, string $mode,
        ?CarbonImmutable $first, ?CarbonImmutable $last,
    ): int {
        $venueStep = (int) $policy->time_step_minutes;
        $courtStep = (int) ($court->time_step_minutes ?? $venueStep);
        if ($courtStep < 1 || $courtStep > 240 || $courtStep % $venueStep !== 0) {
            throw ValidationException::withMessages([
                'include_prices' => 'Шаг аренды зала «'.$court->name.'» должен быть кратен шагу площадки.',
            ]);
        }

        if ($mode !== 'full') {
            $court->calendarSlotPrices()
                ->whereBetween('date', [$first->toDateString(), $last->toDateString()])->delete();
            $rows = [];
            for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
                for ($minute = 0; $minute + $courtStep <= 1440; $minute += $courtStep) {
                    $value = $this->priceAt($policy, $weekly, $calendar, $day, $minute, $courtStep, $venueStep);
                    $rows[] = $this->calendarRow($court, $day, $minute, $value);
                }
            }
            $this->insertChunks('venue_court_calendar_slot_prices', $rows);

            return count($rows);
        }

        // Full synchronization replaces the court's own pricing snapshot.
        // No changes to payment flags, booking rules, holds or booked events.
        $court->update([
            'whole_price_per_step_minor' => (int) $policy->whole_price_per_step_minor * intdiv($courtStep, $venueStep),
            'half_price_per_step_minor' => (int) $policy->half_price_per_step_minor * intdiv($courtStep, $venueStep),
        ]);
        $court->slotPrices()->delete();
        $court->calendarSlotPrices()->delete();

        $weeklyRows = [];
        // 2026-11-02 is a Monday, used only to derive ISO weekdays.
        $monday = CarbonImmutable::parse('2026-11-02', 'Europe/Moscow');
        foreach (range(0, 6) as $dayOffset) {
            $day = $monday->addDays($dayOffset);
            for ($minute = 0; $minute + $courtStep <= 1440; $minute += $courtStep) {
                $value = $this->priceAt($policy, $weekly, [], $day, $minute, $courtStep, $venueStep);
                if (! $value['weekly_override']) {
                    continue;
                }
                $weeklyRows[] = [
                    'venue_court_id' => $court->id,
                    'day_of_week' => $day->dayOfWeekIso,
                    'starts_at' => $this->time($minute).':00',
                    'whole_price_per_step_minor' => $value['whole'],
                    'half_price_per_step_minor' => $value['half'],
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        $this->insertChunks('venue_court_slot_prices', $weeklyRows);

        $dateStrings = array_unique(array_map(
            fn (string $key): string => substr($key, 0, 10), array_keys($calendar),
        ));
        sort($dateStrings);
        $calendarRows = [];
        foreach ($dateStrings as $dateString) {
            $day = CarbonImmutable::parse($dateString, 'Europe/Moscow');
            for ($minute = 0; $minute + $courtStep <= 1440; $minute += $courtStep) {
                $value = $this->priceAt($policy, $weekly, $calendar, $day, $minute, $courtStep, $venueStep);
                if ($value['calendar_override']) {
                    $calendarRows[] = $this->calendarRow($court, $day, $minute, $value);
                }
            }
        }
        $this->insertChunks('venue_court_calendar_slot_prices', $calendarRows);

        return count($weeklyRows) + count($calendarRows) + 2;
    }

    /**
     * @param array<string, object> $weekly
     * @param array<string, object> $calendar
     * @return array{whole: int, half: int, weekly_override: bool, calendar_override: bool}
     */
    private function priceAt(
        VenueBookingPolicy $policy, array $weekly, array $calendar,
        CarbonImmutable $day, int $minute, int $courtStep, int $venueStep,
    ): array {
        $value = ['whole' => 0, 'half' => 0, 'weekly_override' => false, 'calendar_override' => false];
        for ($offset = 0; $offset < $courtStep; $offset += $venueStep) {
            $time = $this->time($minute + $offset);
            $weekPrice = $weekly[$day->dayOfWeekIso.'|'.$time] ?? null;
            $datePrice = $calendar[$day->toDateString().'|'.$time] ?? null;
            foreach (['whole', 'half'] as $scope) {
                $column = $scope.'_price_per_step_minor';
                $value[$scope] += (int) ($datePrice?->{$column}
                    ?? $weekPrice?->{$column}
                    ?? $policy->{$column}
                    ?? 0);
                if ($weekPrice?->{$column} !== null) {
                    $value['weekly_override'] = true;
                }
                if ($datePrice?->{$column} !== null) {
                    $value['calendar_override'] = true;
                }
            }
        }

        return $value;
    }

    /** @param array{whole: int, half: int} $price */
    private function calendarRow(VenueCourt $court, CarbonImmutable $day, int $minute, array $price): array
    {
        return [
            'venue_court_id' => $court->id,
            'date' => $day->toDateString(),
            'starts_at' => $this->time($minute).':00',
            'whole_price_per_step_minor' => $price['whole'],
            'half_price_per_step_minor' => $price['half'],
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function insertChunks(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    private function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function date(string $value, string $zone): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw ValidationException::withMessages(['from_date' => 'Укажите дату в формате ГГГГ-ММ-ДД.']);
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $zone);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['from_date' => 'Некорректная дата.']);
        }
        if ($date === false || $date->toDateString() !== $value) {
            throw ValidationException::withMessages(['from_date' => 'Некорректная дата.']);
        }

        return $date;
    }
}
