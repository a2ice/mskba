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
                    $prices[$scope] = $court === null
                        ? (int) $value
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
