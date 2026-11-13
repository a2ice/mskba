<?php

namespace App\Modules\VenueIntegration\Application\Services;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\VenueIntegration\Domain\Models\ExternalAvailabilitySnapshot;
use App\Modules\VenueIntegration\Domain\Models\VenueIntegrationSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Task 307: append-only manual snapshot import. No changes to working
 * hours, VenueBookingPolicy, reservations or payment state.
 */
final class ExternalAvailabilitySnapshotRecorder
{
    private const PARSER_VERSION = 'findsport-readonly-v1';
    private const ALLOWED_STATUSES = ['available', 'booked', 'unavailable', 'unknown'];

    /** @param array<string, mixed> $report */
    public function record(
        VenueIntegrationSource $source,
        User $actor,
        array $report,
    ): ExternalAvailabilitySnapshot {
        app(VenueIntegrationManager::class)->requirePermission(
            $actor, Permission::BACKOFFICE_VENUE_INTEGRATIONS_EXECUTE,
        );

        if ($source->integration?->mode !== 'test' || $source->adapter_key !== 'api') {
            throw new RuntimeException('Снимок пилота можно сохранить только для API-источника в тестовом режиме.');
        }
        if (! in_array($source->permission_status, ['testing_only', 'mskba_allowed'], true)
            || ! $source->access_reviewed_at || blank($source->permission_reference)) {
            throw new RuntimeException('У источника отсутствует проверенное основание допустимого доступа.');
        }
        if ($source->courtMappings()->count() !== 1) {
            throw new RuntimeException('Пилот поддерживает ровно одну привязку к залу MSKBA.');
        }

        $mapping = $source->courtMappings()->firstOrFail();
        if (($report['provider'] ?? null) !== 'FindSport'
            || (int) ($report['source_id'] ?? -1) !== (int) $source->id
            || (int) ($report['venue_court_id'] ?? -1) !== (int) $mapping->venue_court_id
            || (string) ($report['external_id'] ?? '') !== (string) $source->external_id
            || ! preg_match('/^[1-9]\d*$/D', (string) ($report['external_court_id'] ?? ''))
            || ($mapping->external_court_id !== null
                && (string) $mapping->external_court_id !== (string) $report['external_court_id'])
            || ($report['timezone'] ?? null) !== 'Europe/Moscow'
            || ($report['price_currency'] ?? null) !== 'RUB'
            || (int) ($report['step_minutes'] ?? 0) !== 30
            || (int) ($report['minimum_minutes'] ?? 0) < 60) {
            throw new RuntimeException('Данные снимка не соответствуют источнику, залу или поддерживаемому формату.');
        }

        $days = $report['days'] ?? null;
        if (! is_array($days) || count($days) !== 7 || ! array_is_list($days)) {
            throw new RuntimeException('Ожидались семь последовательных дат из недельного ответа FindSport.');
        }

        try {
            $observed = CarbonImmutable::parse((string) ($report['observed_at'] ?? ''))->utc();
        } catch (\Throwable) {
            throw new RuntimeException('В снимке отсутствует корректное время получения.');
        }
        if ($observed->isFuture() || $observed->lessThan(now()->subDay())) {
            throw new RuntimeException('Снимок слишком старый или его время находится в будущем.');
        }

        $rows = [];
        $countsByDay = [];
        $coverageStart = null;
        $coverageEnd = null;
        $allComplete = true;
        $previousDate = null;

        foreach ($days as $day) {
            if (! is_array($day) || ! is_string($day['date'] ?? null)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day['date'])) {
                throw new RuntimeException('Недопустимая дата в недельном снимке.');
            }
            $date = $day['date'];
            if (($previousDate !== null && $date !== $previousDate->addDay()->toDateString())
                || ($previousDate === null && $date !== $observed->setTimezone('Europe/Moscow')->toDateString())) {
                throw new RuntimeException('Даты снимка пропущены или не соответствуют дню получения.');
            }
            $previousDate = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Europe/Moscow');
            if ($previousDate === false || $previousDate->toDateString() !== $date) {
                throw new RuntimeException('Неверная календарная дата в снимке.');
            }

            $slots = $day['slots'] ?? null;
            if (! is_array($slots) || ! array_is_list($slots) || count($slots) !== 34) {
                throw new RuntimeException("Для {$date} ожидалось 34 получасовых интервала.");
            }

            $dayComplete = ($day['complete'] ?? false) === true;
            $dayCounts = array_fill_keys(self::ALLOWED_STATUSES, 0);
            $seen = [];

            foreach ($slots as $slot) {
                if (! is_array($slot) || ! is_string($slot['time'] ?? null)
                    || ! is_string($slot['ends_at'] ?? null)
                    || ! in_array($slot['status'] ?? null, self::ALLOWED_STATUSES, true)
                    || ! preg_match('/^\d{2}:\d{2}$/D', $slot['time'])
                    || ! preg_match('/^\d{2}:\d{2}$/D', $slot['ends_at'])) {
                    throw new RuntimeException("Снимок {$date}: неверный временной интервал или статус.");
                }
                $time = $slot['time'];
                $expectedTime = gmdate('H:i', 25200 + count($seen) * 1800);
                if ($time !== $expectedTime) {
                    throw new RuntimeException("Снимок {$date}: нарушена последовательность интервалов, ожидалось {$expectedTime}.");
                }
                if (isset($seen[$time])) {
                    throw new RuntimeException("Снимок {$date}: повторяется слот {$time}.");
                }
                $seen[$time] = true;
                $price = $slot['price_minor'] ?? null;
                if ($price !== null && (! is_int($price) || $price < 0)) {
                    throw new RuntimeException("Снимок {$date}: цена должна быть целым числом копеек.");
                }
                if (! $dayComplete && $slot['status'] === 'available') {
                    throw new RuntimeException('Неполный день не может содержать подтверждённые свободные интервалы.');
                }

                $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$time}", 'Europe/Moscow');
                $endDate = $slot['ends_at'] === '00:00' ? $previousDate->addDay()->toDateString() : $date;
                $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$endDate} {$slot['ends_at']}", 'Europe/Moscow');
                if ($start === false || $end === false
                    || $start->format('Y-m-d H:i') !== "{$date} {$time}"
                    || $end->format('Y-m-d H:i') !== "{$endDate} {$slot['ends_at']}"
                    || $start->diffInMinutes($end) !== 30) {
                    throw new RuntimeException("Снимок {$date}: слот {$time} не соответствует 30 минутам.");
                }

                $startUtc = $start->utc();
                $endUtc = $end->utc();
                $coverageStart = $coverageStart === null || $startUtc->lessThan($coverageStart) ? $startUtc : $coverageStart;
                $coverageEnd = $coverageEnd === null || $endUtc->greaterThan($coverageEnd) ? $endUtc : $coverageEnd;

                $dayCounts[$slot['status']]++;
                $rows[] = [
                    'local_date' => $date,
                    'starts_at' => $startUtc->toIso8601String(),
                    'ends_at' => $endUtc->toIso8601String(),
                    'status' => $slot['status'],
                    'price_minor' => $price,
                    'currency' => 'RUB',
                    'price_unit_minutes' => 30,
                    'day_complete' => $dayComplete,
                ];
            }
            $fullyKnown = $dayComplete && $dayCounts['unknown'] === 0;
            $allComplete = $allComplete && $fullyKnown;
            $countsByDay[$date] = ['complete' => $fullyKnown, 'counts' => $dayCounts];
        }

        if ($coverageStart === null || $coverageEnd === null || count($rows) !== 238) {
            throw new RuntimeException('Снимок не покрывает ожидаемую недельную сетку.');
        }

        $checksum = hash('sha256', json_encode($days, JSON_THROW_ON_ERROR));
        $key = hash('sha256', implode('|', [
            $source->id, $mapping->venue_court_id, $observed->toIso8601String(), $checksum,
        ]));

        return DB::transaction(function () use (
            $source, $actor, $report, $mapping, $observed, $coverageStart, $coverageEnd,
            $allComplete, $countsByDay, $checksum, $key, $rows,
        ): ExternalAvailabilitySnapshot {
            $snapshot = ExternalAvailabilitySnapshot::query()->firstOrCreate(
                ['snapshot_key' => $key],
                [
                    'venue_integration_source_id' => $source->id,
                    'venue_id' => $source->integration->venue_id,
                    'venue_court_id' => $mapping->venue_court_id,
                    'created_by_user_id' => $actor->id,
                    'external_court_id' => (string) $report['external_court_id'],
                    'provider' => 'FindSport',
                    'timezone' => 'Europe/Moscow',
                    'parser_version' => self::PARSER_VERSION,
                    'checksum' => $checksum,
                    'currency' => 'RUB',
                    'step_minutes' => 30,
                    'minimum_minutes' => (int) $report['minimum_minutes'],
                    'freshness_ttl_seconds' => $source->integration->freshness_ttl_seconds,
                    'complete' => $allComplete,
                    'observed_at' => $observed,
                    'coverage_start_at' => $coverageStart,
                    'coverage_end_at' => $coverageEnd,
                    'diagnostic' => ['kind' => 'manual_read_only', 'days' => $countsByDay],
                ],
            );
            if (! $snapshot->wasRecentlyCreated) {
                return $snapshot;
            }

            $withId = array_map(static fn (array $slot): array => [
                ...$slot,
                'external_availability_snapshot_id' => $snapshot->id,
            ], $rows);
            foreach (array_chunk($withId, 200) as $chunk) {
                DB::table('external_availability_slots')->insert($chunk);
            }

            DB::table('venue_integration_audit_events')->insert([
                'venue_integration_id' => $source->venue_integration_id,
                'venue_integration_source_id' => $source->id,
                'actor_user_id' => $actor->id,
                'action' => 'source.snapshot_saved',
                'changes' => json_encode([
                    'snapshot_id' => $snapshot->id,
                    'slots' => count($rows),
                    'complete' => $allComplete,
                    'checksum_prefix' => substr($checksum, 0, 12),
                ], JSON_THROW_ON_ERROR),
                'metadata' => json_encode(['execution' => 'manual_read_only'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $snapshot;
        });
    }
}
