<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Modules\VenueIntegration\Domain\Models\VenueCourtExternalScheduleImport;
use App\Modules\VenueIntegration\Domain\Models\ExternalAvailabilitySlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The UI groups adjacent price steps; the storage and quotes remain step-based.
 *
 * External integrations own their native calendar rows. Manual court prices live
 * in a separate overlay so that a subsequent snapshot can never erase them.
 */
final class VenueTariffGrid
{
    private const DAYS = [
        1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда',
        4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье',
    ];

    public function step(VenueBookingPolicy $policy, ?VenueCourt $court): int
    {
        return (int) ($court?->time_step_minutes ?? $policy->time_step_minutes);
    }

    /**
     * Return only persisted, explicitly configured price ranges for editing.
     * A court's imported calendar and inherited venue prices must NOT become
     * manual overrides merely because the user opens and saves the editor.
     *
     * @return list<array<string, mixed>>
     */
    public function editableRanges(
        Venue $venue, VenueBookingPolicy $policy, ?VenueCourt $court,
        string $mode = 'weekly', ?string $date = null
    ): array {
        $isDate = $mode === 'date';
        $step = $this->step($policy, $court);
        $venueStep = (int) $policy->time_step_minutes;
        if ($step < 1 || $step > 240 || $venueStep < 1 || $step % $venueStep !== 0) {
            throw ValidationException::withMessages(['court' => 'Шаг зала несовместим с шагом площадки.']);
        }
        if ($isDate && ($date === null || ! CarbonImmutable::hasFormat($date, 'Y-m-d'))) {
            throw ValidationException::withMessages(['date' => 'Выберите корректную дату тарифа.']);
        }

        if ($court !== null) {
            $query = DB::table('venue_court_manual_slot_prices')
                ->where('venue_court_id', $court->id);
        } else {
            $query = DB::table($isDate ? 'venue_calendar_slot_prices' : 'venue_schedule_slot_prices')
                ->where('venue_id', $venue->id);
        }

        $records = $query
            ->when($isDate, fn ($q) => $q->whereDate('date', $date),
                fn ($q) => $court === null ? $q : $q->whereNull('date'))
            ->orderBy($isDate ? 'starts_at' : 'day_of_week')
            ->orderBy('starts_at')
            ->get();

        $formatter = app(MinorAmountParser::class);
        $parts = [];
        foreach ($records as $record) {
            $day = $isDate ? (int) CarbonImmutable::parse($date)->dayOfWeekIso : (int) $record->day_of_week;
            $minute = self::minutes((string) $record->starts_at);
            if ($day < 1 || $day > 7 || $minute < 0 || $minute + $step > 1440 || $minute % $step !== 0) {
                continue;
            }
            $whole = $record->whole_price_per_step_minor === null
                ? '' : $formatter->format((int) $record->whole_price_per_step_minor, $policy->currency);
            $half = $record->half_price_per_step_minor === null
                ? '' : $formatter->format((int) $record->half_price_per_step_minor, $policy->currency);
            $last = array_key_last($parts);
            if ($last !== null && $parts[$last]['day'] === $day
                && $parts[$last]['endMinute'] === $minute
                && $parts[$last]['whole_price_per_step'] === $whole
                && $parts[$last]['half_price_per_step'] === $half) {
                $parts[$last]['endMinute'] += $step;
                continue;
            }
            $parts[] = [
                'day' => $day,
                'startMinute' => $minute,
                'endMinute' => $minute + $step,
                'whole_price_per_step' => $whole,
                'half_price_per_step' => $half,
            ];
        }

        // Collapse identical bands across weekdays into one editable range.
        $grouped = [];
        foreach ($parts as $part) {
            $endTime = $part['endMinute'] === 1440 ? '00:00' : self::clock($part['endMinute']);
            $signature = implode('|', [
                $part['startMinute'], $part['endMinute'],
                $part['whole_price_per_step'], $part['half_price_per_step'],
            ]);
            if (! $isDate && isset($grouped[$signature])) {
                $grouped[$signature]['days'][] = $part['day'];
                continue;
            }
            $grouped[$isDate ? count($grouped) : $signature] = [
                'days' => [$part['day']],
                'starts_at' => self::clock($part['startMinute']),
                'ends_at' => $endTime,
                'operation' => 'set',
                'whole_price_per_step' => $part['whole_price_per_step'],
                'half_price_per_step' => $part['half_price_per_step'],
                'exceptions' => [],
            ];
        }

        return array_values($grouped);
    }

    /**
     * Apply several named time ranges as one atomic operation. The same code
     * path serves Filament and the public venue editor.
     *
     * @param list<array<string, mixed>> $ranges
     */
    public function applyMany(
        Venue $venue, VenueBookingPolicy $policy, ?VenueCourt $court,
        string $mode, string $date, array $ranges, ?int $userId, bool $replaceExisting = false
    ): int {
        if ((! $replaceExisting && $ranges === []) || count($ranges) > 16) {
            throw ValidationException::withMessages(['ranges' => 'Укажите не более 16 тарифных диапазонов.']);
        }

        $step = $this->step($policy, $court);
        $seen = [];
        foreach ($ranges as $position => $range) {
            $from = self::minutes((string) ($range['starts_at'] ?? ''));
            $to = self::minutes((string) ($range['ends_at'] ?? ''));
            if ($to === 0) {
                $to = 1440;
            }
            if ($from >= $to || $from % $step !== 0 || $to % $step !== 0) {
                throw ValidationException::withMessages([
                    'ranges.'.$position.'.starts_at' => 'Начало и конец должны соответствовать шагу '. $step.' минут.',
                ]);
            }
            $days = $mode === 'date'
                ? [(int) CarbonImmutable::parse($date)->dayOfWeekIso]
                : array_values(array_unique(array_map('intval', $range['days'] ?? [])));
            foreach ($days as $day) {
                for ($minute = $from; $minute < $to; $minute += $step) {
                    $key = $day.'|'.$minute;
                    if (isset($seen[$key])) {
                        throw ValidationException::withMessages([
                            'ranges.'.$position.'.starts_at' => 'Тарифные диапазоны пересекаются. Настройте исключение внутри диапазона вместо наложения.',
                        ]);
                    }
                    $seen[$key] = true;
                }
            }
        }

        return DB::transaction(function () use ($venue, $policy, $court, $mode, $date, $ranges, $userId, $replaceExisting): int {
            // This operation represents the *entire* set displayed in the editor.
            // Removing a range must remove the saved slots too, without touching
            // externally imported court prices.
            if ($replaceExisting) {
                if ($court !== null) {
                    VenueCourt::query()->whereKey($court->id)->lockForUpdate()->firstOrFail();
                    DB::table('venue_court_manual_slot_prices')
                        ->where('venue_court_id', $court->id)
                        ->when($mode === 'date', fn ($q) => $q->whereDate('date', $date),
                            fn ($q) => $q->whereNull('date'))
                        ->delete();
                } else {
                    Venue::query()->whereKey($venue->id)->lockForUpdate()->firstOrFail();
                    DB::table($mode === 'date' ? 'venue_calendar_slot_prices' : 'venue_schedule_slot_prices')
                        ->where('venue_id', $venue->id)
                        ->when($mode === 'date', fn ($q) => $q->whereDate('date', $date))
                        ->delete();
                }
            }

            $count = 0;
            foreach ($ranges as $index => $range) {
                try {
                    $count += $this->apply($venue, $policy, $court, [
                        ...$range,
                        'mode' => $mode,
                        'date' => $date,
                    ], $userId);
                } catch (ValidationException $e) {
                    // Nested Filament / public form fields need nested error keys.
                    // A top-level "whole_price_per_step" error was invisible in
                    // the modal and made saving appear to do nothing.
                    $messages = [];
                    foreach ($e->errors() as $field => $errors) {
                        $messages['ranges.'.$index.'.'.$field] = $errors;
                    }
                    throw ValidationException::withMessages($messages);
                }
            }

            return $count;
        }, 3);
    }

    /**
     * @return array<int, array{day: int, label: string, ranges: array<int, array<string, mixed>>}>
     */
    public function read(Venue $venue, VenueBookingPolicy $policy, ?VenueCourt $court, string $mode, string $date): array
    {
        $step = $this->step($policy, $court);
        $venueStep = (int) $policy->time_step_minutes;
        if ($step < 1 || $step > 240 || $step % $venueStep !== 0) {
            throw ValidationException::withMessages(['court' => 'Шаг зала несовместим с шагом площадки.']);
        }

        $weeklyVenue = $venue->scheduleSlotPrices()->get()
            ->keyBy(fn ($r) => $r->day_of_week.'|'.substr((string) $r->starts_at, 0, 5));
        $weeklyCourt = $court?->slotPrices()->get()
            ->keyBy(fn ($r) => $r->day_of_week.'|'.substr((string) $r->starts_at, 0, 5)) ?? collect();
        $calendarVenue = $mode === 'date'
            ? $venue->calendarSlotPrices()->whereDate('date', $date)->get()
                ->keyBy(fn ($r) => substr((string) $r->starts_at, 0, 5))
            : collect();
        $calendarCourt = $mode === 'date'
            ? ($court?->calendarSlotPrices()->whereDate('date', $date)->get()
                ->keyBy(fn ($r) => substr((string) $r->starts_at, 0, 5)) ?? collect())
            : collect();

        $manual = $court === null ? collect() : DB::table('venue_court_manual_slot_prices')
            ->where('venue_court_id', $court->id)
            ->when($mode === 'date',
                fn ($q) => $q->whereDate('date', $date),
                fn ($q) => $q->whereNull('date'))
            ->get()->keyBy(fn ($r) => ($mode === 'date' ? '' : $r->day_of_week.'|').substr((string) $r->starts_at, 0, 5));
        // A saved weekly override also applies to calendar dates and survives imports.
        $manualWeekly = ($court !== null && $mode === 'date')
            ? DB::table('venue_court_manual_slot_prices')
                ->where('venue_court_id', $court->id)->whereNull('date')->get()
                ->keyBy(fn ($r) => $r->day_of_week.'|'.substr((string) $r->starts_at, 0, 5))
            : collect();

        $import = $court === null ? null : VenueCourtExternalScheduleImport::query()
            ->where('venue_court_id', $court->id)->first();
        $fromImport = $mode === 'date' && $import !== null
            && $date >= $import->first_date->format('Y-m-d')
            && $date <= $import->last_date->format('Y-m-d');
        $availability = $fromImport
            ? ExternalAvailabilitySlot::query()
                ->where('external_availability_snapshot_id', $import->external_availability_snapshot_id)
                ->whereDate('local_date', $date)->get()
                ->keyBy(fn ($slot) => $slot->starts_at->setTimezone('Europe/Moscow')->format('H:i'))
            : collect();

        $days = $mode === 'date'
            ? [(int) CarbonImmutable::parse($date)->dayOfWeekIso => CarbonImmutable::parse($date)->format('d.m.Y')]
            : self::DAYS;
        $result = [];

        foreach ($days as $day => $label) {
            $ranges = [];
            for ($minute = 0; $minute + $step <= 1440; $minute += $step) {
                $time = self::clock($minute);
                $weekKey = $day.'|'.$time;
                $manualRow = $manual->get($mode === 'date' ? $time : $weekKey);
                $manualWeekRow = $manualWeekly->get($weekKey);
                $courtDateRow = $calendarCourt->get($time);
                $courtWeekRow = $weeklyCourt->get($weekKey);
                $venueDateRow = $calendarVenue->get($time);

                $prices = [];
                $source = 'Базовая цена';
                foreach (['whole', 'half'] as $scope) {
                    $column = $scope.'_price_per_step_minor';
                    $floor = (int) ($policy->{$column} ?? 0);
                    $ratio = intdiv($step, $venueStep);

                    // Explicit manual overrides win over a refreshed imported calendar.
                    $value = $manualRow?->{$column} ?? $manualWeekRow?->{$column};
                    if ($value !== null) {
                        $source = 'Ручная корректировка';
                    } else {
                        $value = $courtDateRow?->{$column} ?? $courtWeekRow?->{$column} ?? $court?->{$column};
                        if ($value !== null) {
                            if ($source !== 'Ручная корректировка') {
                                $source = $courtDateRow?->{$column} !== null
                                    ? ($fromImport ? 'Интеграция' : 'Цена на дату зала')
                                    : ($courtWeekRow?->{$column} !== null ? 'Недельная цена зала' : 'Базовая цена зала');
                            }
                        } else {
                            $value = 0;
                            for ($offset = 0; $offset < $step; $offset += $venueStep) {
                                $subtime = self::clock($minute + $offset);
                                $venueOverride = $mode === 'date'
                                    ? $calendarVenue->get($subtime)?->{$column} : null;
                                $venueOverride ??= $weeklyVenue->get($day.'|'.$subtime)?->{$column};
                                if ($venueOverride !== null && $source === 'Базовая цена') {
                                    $source = 'Цена площадки';
                                }
                                $value += max($floor, (int) ($venueOverride ?? $floor));
                            }
                        }
                    }
                    // Match VenueSlotPricing: even historical low overrides are
                    // displayed at the payable minimum, not their raw value.
                    $prices[$scope] = $court === null
                        ? max((int) $value, $floor)
                        : max((int) $value, $floor * $ratio);
                }

                $signature = implode('|', [$prices['whole'], $prices['half'], $source]);
                $end = self::clock(($minute + $step) % 1440);
                $externalStatus = $availability->get($time)?->status;
                $cell = [
                    'time' => $time, 'whole' => $prices['whole'], 'half' => $prices['half'],
                    'source' => $source,
                    'availability' => match ($externalStatus) {
                        'available' => 'Свободно', 'booked' => 'Занято',
                        'unavailable' => 'Недоступно', 'unknown' => 'Неизвестно',
                        default => null,
                    },
                ];
                $last = array_key_last($ranges);
                if ($last !== null && $ranges[$last]['signature'] === $signature) {
                    $ranges[$last]['end'] = $end;
                    $ranges[$last]['cells'][] = $cell;
                } else {
                    $ranges[] = [
                        'start' => $time, 'end' => $end, 'whole' => $prices['whole'],
                        'half' => $prices['half'], 'source' => $source,
                        'signature' => $signature, 'cells' => [$cell],
                    ];
                }
            }
            $result[] = ['day' => (int) $day, 'label' => $label, 'ranges' => $ranges];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data Request-validated structure
     */
    public function apply(Venue $venue, VenueBookingPolicy $policy, ?VenueCourt $court, array $data, ?int $userId): int
    {
        $step = $this->step($policy, $court);
        $venueStep = (int) $policy->time_step_minutes;
        if ($step < 1 || $step % $venueStep !== 0 || $step > 240) {
            throw ValidationException::withMessages(['court_id' => 'Шаг зала должен быть кратен шагу площадки.']);
        }

        $from = self::minutes($data['starts_at']);
        $to = self::minutes($data['ends_at']);
        if ($to === 0 || $to === 1439) {
            $to = 1440;
        }
        if ($from >= $to || $from % $step !== 0 || $to % $step !== 0) {
            throw ValidationException::withMessages(['starts_at' => 'Выберите диапазон в пределах суток, кратный шагу '. $step.' минут. Для конца суток укажите 00:00.']);
        }

        $isDate = $data['mode'] === 'date';
        $days = $isDate
            ? [(int) CarbonImmutable::parse($data['date'])->dayOfWeekIso]
            : array_map('intval', array_unique($data['days'] ?? []));
        if ($days === [] || count(array_diff($days, array_keys(self::DAYS))) > 0) {
            throw ValidationException::withMessages(['days' => 'Выберите корректные дни недели.']);
        }
        $operation = $data['operation'];
        $base = $operation === 'clear' ? null : $this->parsePrices($policy, $court, $data);

        $exceptions = [];
        foreach ($data['exceptions'] ?? [] as $exception) {
            if (empty($exception['starts_at'])) {
                continue;
            }
            $minute = self::minutes($exception['starts_at']);
            $exceptDay = (int) ($exception['day_of_week'] ?? 0);
            $key = $exceptDay.'|'.$minute;
            if ($minute < $from || $minute >= $to || $minute % $step !== 0
                || ($exceptDay > 0 && ! in_array($exceptDay, $days, true))
                || array_key_exists($key, $exceptions)) {
                throw ValidationException::withMessages(['exceptions' => 'Исключение должно относиться к уникальному шагу выбранного дня внутри диапазона.']);
            }
            $exceptions[$key] = ($exception['operation'] ?? 'set') === 'clear'
                ? null : $this->parsePrices($policy, $court, $exception);
        }

        $count = 0;
        DB::transaction(function () use (
            $venue, $court, $isDate, $days, $data, $from, $to, $step,
            $operation, $base, $exceptions, $userId, &$count
        ): void {
            // Synchronizers lock the court before replacing native source rows.
            // Manual overlays are independent and protected by the same lock.
            if ($court !== null) {
                VenueCourt::query()->whereKey($court->id)->lockForUpdate()->firstOrFail();
            } else {
                Venue::query()->whereKey($venue->id)->lockForUpdate()->firstOrFail();
            }

            foreach ($days as $day) {
                for ($minute = $from; $minute < $to; $minute += $step) {
                    $specific = $day.'|'.$minute;
                    $anyDay = '0|'.$minute;
                    $price = array_key_exists($specific, $exceptions)
                        ? $exceptions[$specific]
                        : (array_key_exists($anyDay, $exceptions) ? $exceptions[$anyDay] : $base);
                    $this->writeStep(
                        $venue, $court, $isDate ? $data['date'] : null,
                        $isDate ? null : $day, self::clock($minute),
                        $price, $userId,
                    );
                    $count++;
                }
            }
        }, 3);

        return $count;
    }

    /** @return array{whole_price_per_step_minor: ?int, half_price_per_step_minor: ?int} */
    private function parsePrices(VenueBookingPolicy $policy, ?VenueCourt $court, array $row): array
    {
        $parsed = [];
        $ratio = intdiv($this->step($policy, $court), (int) $policy->time_step_minutes);
        foreach (['whole', 'half'] as $scope) {
            $key = $scope.'_price_per_step';
            $raw = trim((string) ($row[$key] ?? ''));
            try {
                $amount = $raw === '' ? null : app(MinorAmountParser::class)->parse($raw, $policy->currency);
            } catch (VenueBookingTransitionException $e) {
                throw ValidationException::withMessages([$key => $e->getMessage()]);
            }
            $min = (int) ($policy->{$scope.'_price_per_step_minor'} ?? 0) * $ratio;
            if ($amount !== null && $policy->requires_payment && $amount < $min) {
                throw ValidationException::withMessages([$key => 'Цена ниже минимального тарифа площадки за шаг.']);
            }
            $parsed[$key.'_minor'] = $amount;
        }
        if ($parsed['whole_price_per_step_minor'] === null && $parsed['half_price_per_step_minor'] === null) {
            throw ValidationException::withMessages(['whole_price_per_step' => 'Укажите хотя бы одну цену или выберите сброс.']);
        }

        return $parsed;
    }

    /** @param array<string, int|null>|null $price */
    private function writeStep(Venue $venue, ?VenueCourt $court, ?string $date, ?int $day, string $time, ?array $price, ?int $userId): void
    {
        if ($court !== null) {
            $query = DB::table('venue_court_manual_slot_prices')
                ->where('venue_court_id', $court->id)
                ->where('starts_at', $time.':00')
                ->when($date !== null,
                    fn ($q) => $q->where('date', $date)->whereNull('day_of_week'),
                    fn ($q) => $q->where('day_of_week', $day)->whereNull('date'));
            if ($price === null) {
                $query->delete();

                return;
            }
            $values = $price + [
                'venue_court_id' => $court->id, 'date' => $date,
                'day_of_week' => $day, 'starts_at' => $time.':00',
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ];
            if ($query->exists()) {
                $query->update(array_intersect_key($values, array_flip([
                    'whole_price_per_step_minor', 'half_price_per_step_minor',
                    'updated_by_user_id', 'updated_at',
                ])));
            } else {
                DB::table('venue_court_manual_slot_prices')->insert($values + ['created_at' => now()]);
            }

            return;
        }

        $isDate = $date !== null;
        $table = $isDate ? 'venue_calendar_slot_prices' : 'venue_schedule_slot_prices';
        $keys = ['venue_id' => $venue->id, 'starts_at' => $time.':00'];
        $keys[$isDate ? 'date' : 'day_of_week'] = $isDate ? $date : $day;
        $query = DB::table($table)->where($keys);
        if ($price === null) {
            $query->delete();

            return;
        }
        if ($query->exists()) {
            $query->update($price + ['updated_at' => now()]);
        } else {
            DB::table($table)->insert($keys + $price + ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    private static function minutes(string $value): int
    {
        return ((int) substr($value, 0, 2)) * 60 + (int) substr($value, 3, 2);
    }

    private static function clock(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }
}
