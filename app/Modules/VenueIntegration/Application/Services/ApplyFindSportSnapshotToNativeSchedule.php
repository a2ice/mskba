<?php

namespace App\Modules\VenueIntegration\Application\Services;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Modules\VenueIntegration\Domain\Models\ExternalAvailabilitySnapshot;
use App\Modules\VenueIntegration\Domain\Models\VenueCourtExternalScheduleImport;
use App\Modules\VenueIntegration\Domain\Models\VenueIntegrationSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Explicit staging-only native import. Replaces that court's working hours,
 * calendar prices and active external occupancy in one transaction, without
 * creating a fictitious MSKBA booking or exposing a new public widget.
 */
final class ApplyFindSportSnapshotToNativeSchedule
{
    // Current HTML time controls cannot edit 24:00: 23:59 is their closing
    // boundary; a booking crossing midnight is separately unsupported.
    private const CLOSE = '23:59';

    public function apply(
        VenueIntegrationSource $source,
        ExternalAvailabilitySnapshot $snapshot,
        User $actor,
    ): VenueCourtExternalScheduleImport {
        app(VenueIntegrationManager::class)->requirePermission(
            $actor, Permission::BACKOFFICE_VENUE_INTEGRATIONS_EXECUTE,
        );

        return $this->applySnapshot($source, $snapshot, $actor);
    }

    /** Guarded server-side refresh only; leaves the same native validation intact. */
    public function applyFromPublicSchedule(
        VenueIntegrationSource $source,
        ExternalAvailabilitySnapshot $snapshot,
    ): VenueCourtExternalScheduleImport {
        if (! config('venue_integrations.public_schedule_refresh_enabled', false)
            || ! FindSportSourceIdentity::matches($source)) {
            throw new RuntimeException('Автоматическое применение FindSport отключено.');
        }

        return $this->applySnapshot($source, $snapshot, null);
    }

    private function applySnapshot(
        VenueIntegrationSource $source,
        ExternalAvailabilitySnapshot $snapshot,
        ?User $actor,
    ): VenueCourtExternalScheduleImport {
        if (! config('venue_integrations.apply_findsport_native_enabled', false)) {
            throw new RuntimeException('Импорт в штатное расписание разрешён только на тестовом сервере.');
        }

        if (! FindSportSourceIdentity::matches($source)
            || $source->integration?->mode !== 'test'
            || ! in_array($source->permission_status, ['testing_only', 'mskba_allowed'], true)
            || ! $source->access_reviewed_at || blank($source->permission_reference)
            || $source->courtMappings()->count() !== 1
            || ! in_array('availability', $source->capabilities ?? [], true)
            || ! in_array('pricing', $source->capabilities ?? [], true)) {
            throw new RuntimeException('Нужен проверенный тестовый API-источник FindSport с совпадающими ID/URL, ценами, занятостью и ровно одним залом.');
        }

        $mapping = $source->courtMappings()->firstOrFail();
        if ((int) $snapshot->venue_integration_source_id !== (int) $source->id
            || (int) $snapshot->venue_id !== (int) $source->integration->venue_id
            || (int) $snapshot->venue_court_id !== (int) $mapping->venue_court_id
            || ($mapping->external_court_id !== null
                && (string) $mapping->external_court_id !== (string) $snapshot->external_court_id)
            || $snapshot->provider !== 'FindSport'
            || $snapshot->timezone !== 'Europe/Moscow'
            || $snapshot->currency !== 'RUB'
            || ! $snapshot->complete
            || $snapshot->step_minutes !== 30
            || $snapshot->minimum_minutes < 60
            || $snapshot->minimum_minutes % 30 !== 0) {
            throw new RuntimeException('Снимок неполный либо не соответствует источнику, залу или формату FindSport.');
        }

        $days = $this->validatedDays($snapshot);
        $dates = array_keys($days);
        $firstDate = $dates[0];
        $lastDate = $dates[6];
        $firstSlot = $days[$firstDate]->first();
        $lastSlot = $days[$firstDate]->last();
        $openTime = $firstSlot->starts_at->setTimezone('Europe/Moscow')->format('H:i');
        $sourceCloseTime = $lastSlot->ends_at->setTimezone('Europe/Moscow')->format('H:i');
        $closeTime = $sourceCloseTime === '00:00' ? self::CLOSE : $sourceCloseTime;

        return DB::transaction(function () use (
            $source, $snapshot, $actor, $mapping, $days, $dates, $firstDate, $lastDate,
            $openTime, $closeTime,
        ): VenueCourtExternalScheduleImport {
            $court = VenueCourt::query()->whereKey($mapping->venue_court_id)
                ->lockForUpdate()->firstOrFail();
            $venue = Venue::query()->whereKey($source->integration->venue_id)
                ->lockForUpdate()->firstOrFail();

            if ((int) $court->venue_id !== (int) $venue->id || $court->trashed()) {
                throw new RuntimeException('Зал больше не принадлежит выбранной площадке.');
            }
            $onlyCourt = $venue->courts()->count() === 1;
            if (! $onlyCourt) {
                // Multiple halls: keep the parent venue schedule unchanged.
                // An explicitly closed / exceptional parent date would still
                // override this court, so do not silently claim it is available.
                $parent = $venue->schedule()->with('exceptions')->first();
                foreach ($dates as $date) {
                    $parentException = $parent?->exceptions->first(
                        fn ($row): bool => $row->date->toDateString() === $date,
                    );
                    if ($parentException !== null
                        && $days[$date]->contains(fn ($slot): bool => $slot->status === 'available')) {
                        throw new RuntimeException(
                            "У площадки есть исключение расписания на {$date}. Проверьте его, прежде чем импортировать свободные слоты одного из залов."
                        );
                    }
                }
            }

            $previous = VenueCourtExternalScheduleImport::query()
                ->where('venue_court_id', $court->id)->lockForUpdate()->first();
            if ($previous !== null
                && (int) $previous->external_availability_snapshot_id === (int) $snapshot->id) {
                return $previous;
            }

            $policy = VenueBookingPolicy::query()
                ->where('venue_id', $venue->id)
                ->where('active_marker', true)->first();
            if ($policy !== null && (30 % (int) $policy->time_step_minutes !== 0
                || $policy->currency !== 'RUB')) {
                throw new RuntimeException(
                    'Шаг и валюта действующей политики аренды несовместимы. Для FindSport нужен шаг площадки 30 минут (или делитель 30) и RUB.'
                );
            }

            // For single-court venues also update the parent working hours;
            // for multi-court venues only the mapped hall is changed.
            if ($onlyCourt) {
                $schedule = $venue->schedule()->firstOrCreate(
                    ['venue_id' => $venue->id], ['timezone' => 'Europe/Moscow'],
                );
                $schedule->update(['timezone' => 'Europe/Moscow']);
                $schedule->intervals()->delete();
                for ($isoDay = 1; $isoDay <= 7; $isoDay++) {
                    $schedule->intervals()->create([
                        'day_of_week' => $isoDay,
                        'starts_at' => $openTime,
                        'ends_at' => $closeTime,
                        'sort_order' => 0,
                    ]);
                }
            }

            $affectedDates = $dates;
            if ($previous !== null) {
                for ($date = $previous->first_date;
                    $date->lessThanOrEqualTo($previous->last_date);
                    $date = $date->addDay()) {
                    $affectedDates[] = $date->toDateString();
                }
            }
            $affectedDates = array_values(array_unique($affectedDates));
            if ($onlyCourt) {
                $schedule->exceptions()->whereIn('date', $affectedDates)->delete();
            }

            $weekly = [];
            for ($isoDay = 1; $isoDay <= 7; $isoDay++) {
                $weekly[] = [
                    'day_of_week' => $isoDay,
                    'starts_at' => $openTime,
                    'ends_at' => $closeTime,
                ];
            }

            $currentCourtSchedule = $court->schedule()->first();
            $exceptions = collect($currentCourtSchedule?->exceptions ?? [])
                ->filter(fn (array $row): bool => ! in_array($row['date'] ?? null, $affectedDates, true))
                ->values()->all();

            $priceRows = [];
            foreach ($days as $date => $slots) {
                $runs = $this->availableRuns($slots);
                if ($runs === []) {
                    $exceptions[] = ['date' => $date, 'is_closed' => true];
                } else {
                    foreach ($runs as $run) {
                        $exceptions[] = [
                            'date' => $date,
                            'is_closed' => false,
                            'starts_at' => $run['start'],
                            'ends_at' => $run['end'],
                        ];
                    }
                }
                foreach ($slots as $slot) {
                    if ($slot->price_minor === null) {
                        continue;
                    }
                    $priceRows[] = [
                        'venue_court_id' => $court->id,
                        'date' => $date,
                        'starts_at' => $slot->starts_at->setTimezone('Europe/Moscow')->format('H:i:s'),
                        'whole_price_per_step_minor' => $slot->price_minor,
                        'half_price_per_step_minor' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            $court->schedule()->updateOrCreate(
                ['venue_court_id' => $court->id],
                ['weekly_intervals' => $weekly, 'exceptions' => $exceptions],
            );
            // Preserve the existing rental-enabled setting and versioned
            // booking policies; do not enable payments on the pilot.
            $court->forceFill([
                'requires_payment' => true,
                'requires_booking' => true,
                'time_step_minutes' => 30,
                'minimum_duration_minutes' => $snapshot->minimum_minutes,
            ])->save();

            $court->calendarSlotPrices()->whereIn('date', $affectedDates)->delete();
            foreach (array_chunk($priceRows, 180) as $chunk) {
                DB::table('venue_court_calendar_slot_prices')->insert($chunk);
            }

            $import = VenueCourtExternalScheduleImport::query()->updateOrCreate(
                ['venue_court_id' => $court->id],
                [
                    'venue_id' => $venue->id,
                    'venue_integration_source_id' => $source->id,
                    'external_availability_snapshot_id' => $snapshot->id,
                    'applied_by_user_id' => $actor?->id,
                    'first_date' => $firstDate,
                    'last_date' => $lastDate,
                    'applied_at' => now(),
                ],
            );

            DB::table('venue_integration_audit_events')->insert([
                'venue_integration_id' => $source->venue_integration_id,
                'venue_integration_source_id' => $source->id,
                'actor_user_id' => $actor?->id,
                'action' => 'source.snapshot_applied_to_court_schedule',
                'changes' => json_encode([
                    'snapshot_id' => $snapshot->id,
                    'replaced_snapshot_id' => $previous?->external_availability_snapshot_id,
                    'venue_court_id' => $court->id,
                    'first_date' => $firstDate,
                    'last_date' => $lastDate,
                    'price_slots' => count($priceRows),
                    'booked_slots' => $snapshot->slots()->where('status', 'booked')->count(),
                ], JSON_THROW_ON_ERROR),
                'metadata' => json_encode(['execution' => 'manual_staging_native_import'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $import;
        }, 3);
    }

    /**
     * @return array<string, Collection<int, \App\Modules\VenueIntegration\Domain\Models\ExternalAvailabilitySlot>>
     */
    private function validatedDays(ExternalAvailabilitySnapshot $snapshot): array
    {
        $slots = $snapshot->slots()->orderBy('starts_at')->get();
        if ($slots->count() < 7 || $slots->count() > 7 * 48
            || $slots->count() % 7 !== 0) {
            throw new RuntimeException('Снимок содержит недопустимое количество получасовых интервалов.');
        }
        $slotsPerDay = intdiv($slots->count(), 7);
        $days = [];
        foreach ($slots as $slot) {
            $date = $slot->local_date->toDateString();
            $days[$date] ??= collect();
            $days[$date]->push($slot);
        }
        $dates = array_keys($days);
        if (count($dates) !== 7) {
            throw new RuntimeException('Снимок должен охватывать ровно семь дней.');
        }
        $gridStart = $days[$dates[0]]->first()->starts_at
            ->setTimezone('Europe/Moscow')->format('H:i');
        foreach ($dates as $i => $date) {
            if ($date !== CarbonImmutable::parse($dates[0], 'Europe/Moscow')->addDays($i)->toDateString()
                || $days[$date]->count() !== $slotsPerDay) {
                throw new RuntimeException('Обнаружен разрыв в недельной сетке FindSport.');
            }
            foreach ($days[$date]->values() as $j => $slot) {
                $expected = CarbonImmutable::parse($date.' '.$gridStart, 'Europe/Moscow')->addMinutes($j * 30);
                if ($expected->toDateString() !== $date) {
                    throw new RuntimeException("Снимок {$date}: интервалы выходят за границы суток.");
                }
                $actual = $slot->starts_at->setTimezone('Europe/Moscow');
                if ($actual->toIso8601String() !== $expected->toIso8601String()
                    || $slot->ends_at->getTimestamp() - $slot->starts_at->getTimestamp() !== 1800
                    || ! $slot->day_complete
                    || ! in_array($slot->status, ['available', 'booked', 'unavailable'], true)
                    || ($slot->status === 'available' && ($slot->price_minor === null || $slot->price_minor <= 0))) {
                    throw new RuntimeException("Невалидный или неполный интервал FindSport за {$date}.");
                }
            }
        }

        return $days;
    }

    /**
     * @param Collection<int, \App\Modules\VenueIntegration\Domain\Models\ExternalAvailabilitySlot> $slots
     * @return array<int, array{start: string, end: string}>
     */
    private function availableRuns(Collection $slots): array
    {
        $runs = [];
        $start = null;
        $lastEnd = null;
        $finish = function () use (&$runs, &$start, &$lastEnd): void {
            if ($start !== null) {
                $runs[] = [
                    'start' => $start,
                    'end' => $lastEnd === '00:00' ? self::CLOSE : $lastEnd,
                ];
                $start = null;
                $lastEnd = null;
            }
        };

        foreach ($slots as $slot) {
            if ($slot->status !== 'available') {
                $finish();
                continue;
            }
            $startTime = $slot->starts_at->setTimezone('Europe/Moscow')->format('H:i');
            $endTime = $slot->ends_at->setTimezone('Europe/Moscow')->format('H:i');
            if ($start === null) {
                $start = $startTime;
            }
            $lastEnd = $endTime;
        }
        $finish();

        return $runs;
    }
}
