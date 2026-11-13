<?php

namespace App\Modules\VenueIntegration\Application\Services;

use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueIntegration\Infrastructure\Adapters\FindSportReadOnlyAdapter;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Public day-modal refresh. At most one new source read per five minutes
 * per source, shared across visitors. Only an ALREADY APPLIED, verified
 * Basketpoint source can be refreshed; arbitrary public URLs are forbidden.
 */
final class FindSportOnDemandRefresh
{
    public const REFRESH_AFTER_SECONDS = 300;

    public function __construct(
        private readonly AppliedImportedCourtAvailability $applied,
        private readonly FindSportReadOnlyAdapter $adapter,
        private readonly ExternalAvailabilitySnapshotRecorder $recorder,
        private readonly ApplyFindSportSnapshotToNativeSchedule $importer,
    ) {}

    /** @return array{state:string, message:string|null} */
    public function forCourt(VenueCourt $court): array
    {
        $result = static fn (string $state, ?string $message = null): array => compact('state', 'message');

        if (! app()->environment('staging', 'testing')
            || ! config('venue_integrations.public_schedule_refresh_enabled', false)
            || ! config('venue_integrations.findsport_preview_enabled', false)) {
            return $result('disabled');
        }

        $current = $this->applied->activeFor($court);
        if ($current === null) {
            return $result('not_configured');
        }

        $source = $current->source;
        if ($source === null || $source->external_id !== '4805'
            || $source->adapter_key !== 'api'
            || $source->integration?->mode !== 'test'
            || ! in_array($source->permission_status, ['testing_only', 'mskba_allowed'], true)
            || blank($source->permission_reference)
            || ! $source->access_reviewed_at
            || $source->courtMappings()->count() !== 1
            || (int) $source->courtMappings()->value('venue_court_id') !== (int) $court->id) {
            return $result('not_configured');
        }

        $lastSuccessful = $current->snapshot?->observed_at;
        if ($lastSuccessful !== null
            && $lastSuccessful->greaterThan(now()->subSeconds(self::REFRESH_AFTER_SECONDS))) {
            return $result('cached');
        }

        $sourceId = $source->id;
        $retryKey = "venue-integration:findsport:refresh-error:{$sourceId}";
        if (Cache::has($retryKey)) {
            return $result('failed', 'Обновление пока недоступно, показаны последние сохранённые данные.');
        }

        // Atomic lock prevents concurrent visitors from flooding FindSport.
        $lock = Cache::lock("venue-integration:findsport:refresh-lock:{$sourceId}", 45);
        if (! $lock->get()) {
            return $result('updating', 'Расписание уже обновляется другим посетителем.');
        }

        try {
            // Re-read inside the lock to avoid a duplicate request after the
            // first visitor has already completed the refresh.
            $fresh = $this->applied->activeFor($court);
            if ($fresh?->snapshot?->observed_at?->greaterThan(
                now()->subSeconds(self::REFRESH_AFTER_SECONDS),
            )) {
                return $result('cached');
            }

            $report = $this->adapter->readFromPublicSchedule($source);
            $snapshot = $this->recorder->recordFromPublicSchedule($source, $report);
            if (! $snapshot->complete) {
                Cache::put($retryKey, true, now()->addMinute());
                return $result('failed', 'FindSport вернул неполное расписание. Бронирование непроверенных слотов недоступно.');
            }

            $this->importer->applyFromPublicSchedule($source, $snapshot);
            return $result('updated');
        } catch (Throwable $exception) {
            report($exception);
            Cache::put($retryKey, true, now()->addMinute());
            return $result('failed', 'Не удалось обновить FindSport. Показаны последние сохранённые данные.');
        } finally {
            $lock->release();
        }
    }
}
