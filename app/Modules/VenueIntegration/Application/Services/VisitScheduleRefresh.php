<?php

namespace App\Modules\VenueIntegration\Application\Services;

use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueIntegration\Application\Jobs\RefreshVisitedCourtSchedule;
use App\Modules\VenueIntegration\Domain\Models\VenueCourtExternalScheduleImport;
use App\Modules\VenueIntegration\Domain\Models\VenueIntegrationSource;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Read the last-known schedule immediately; only enqueue from the page request.
 * This deliberately supports the reviewed, manually applied FindSport pilot,
 * not arbitrary external URLs or the disabled generic execution engine.
 */
final class VisitScheduleRefresh
{
    public const PENDING_SECONDS = 120;

    public static function enabled(): bool
    {
        return config('venue_integrations.visit_schedule_refresh_enabled', false)
            && config('venue_integrations.public_schedule_refresh_enabled', false)
            && config('venue_integrations.apply_findsport_native_enabled', false)
            && config('venue_integrations.findsport_preview_enabled', false);
    }

    public static function eligibleSource(VenueIntegrationSource $source): bool
    {
        return self::enabled()
            && FindSportSourceIdentity::matches($source)
            && $source->integration?->mode === 'test'
            && in_array($source->permission_status, ['testing_only', 'mskba_allowed'], true)
            && (! app()->isProduction() || $source->permission_status === 'mskba_allowed')
            && filled($source->permission_reference)
            && $source->access_reviewed_at !== null
            && in_array('availability', $source->capabilities ?? [], true)
            && in_array('pricing', $source->capabilities ?? [], true);
    }

    public function eligible(VenueCourtExternalScheduleImport $import): bool
    {
        $source = $import->source;

        return $source !== null
            && self::eligibleSource($source)
            && $source->enabled
            && $source->integration->enabled
            && $source->integration->refresh_on_visit
            && (int) $source->integration->venue_id === (int) $import->venue_id
            && $source->courtMappings()->count() === 1
            && (int) $source->courtMappings()->value('venue_court_id') === (int) $import->venue_court_id
            && $import->court !== null
            && (int) $import->court->venue_id === (int) $import->venue_id;
    }

    public function fresh(VenueCourtExternalScheduleImport $import): bool
    {
        return $import->snapshot !== null
            && $import->snapshot->complete
            && ! $import->snapshot->isStale()
            && $import->snapshot->observed_at->greaterThan(
                now()->subSeconds(FindSportOnDemandRefresh::REFRESH_AFTER_SECONDS),
            );
    }

    public static function pendingKey(int $sourceId): string
    {
        return "venue-integration:visit-pending:{$sourceId}";
    }

    /** @return array<int, array{state:string, observed_at:?string}> */
    public function forVenue(int $venueId, ?int $courtId = null, bool $enqueue = false): array
    {
        if (! self::enabled() || ! Venue::query()->find($venueId)?->isPubliclyVisible()) {
            return [];
        }

        $imports = VenueCourtExternalScheduleImport::query()
            ->where('venue_id', $venueId)
            ->when($courtId !== null, fn ($query) => $query->where('venue_court_id', $courtId))
            ->with(['snapshot', 'source.integration', 'court'])
            ->get();

        $states = [];
        foreach ($imports as $import) {
            if (! $this->eligible($import)) {
                continue;
            }

            $sourceId = (int) $import->venue_integration_source_id;
            $key = self::pendingKey($sourceId);
            $state = 'stale';
            if ($this->fresh($import)) {
                $state = 'cached';
            } elseif (Cache::has("venue-integration:findsport:refresh-error:{$sourceId}")) {
                $state = 'failed';
            } elseif (Cache::has($key)) {
                $state = 'updating';
            } elseif ($enqueue) {
                $token = (string) Str::uuid();
                if (Cache::add($key, $token, self::PENDING_SECONDS)) {
                    try {
                        Bus::dispatch(new RefreshVisitedCourtSchedule(
                            (int) $import->id, $sourceId, $token,
                        ));
                        $state = 'updating';
                    } catch (Throwable $exception) {
                        Cache::forget($key);
                        report($exception);
                        $state = 'failed';
                    }
                } else {
                    $state = 'updating';
                }
            }

            $states[(int) $import->venue_court_id] = [
                'state' => $state,
                'observed_at' => $import->snapshot?->observed_at?->toIso8601String(),
            ];
        }

        return $states;
    }
}
