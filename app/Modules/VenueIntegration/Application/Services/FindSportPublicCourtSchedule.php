<?php

namespace App\Modules\VenueIntegration\Application\Services;

use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueIntegration\Domain\Models\ExternalAvailabilitySnapshot;
use App\Modules\VenueIntegration\Domain\Models\VenueIntegrationSource;
use Carbon\CarbonImmutable;

/**
 * Staging-only display projection for a specific, configured physical court.
 * Never issues HTTP calls, writes tariffs, or changes MSKBA bookings.
 */
final class FindSportPublicCourtSchedule
{
    /**
     * @return array{source_id:int,snapshot_id:int,observed_at:string,stale:bool,timezone:string,days:array<string,array<int,array<string,mixed>>>}|null
     */
    public function forCourt(VenueCourt $court, CarbonImmutable $today): ?array
    {
        if (! config('venue_integrations.findsport_public_snapshot_enabled', false)) {
            return null;
        }

        $source = VenueIntegrationSource::query()
            ->where('adapter_key', 'api')
            ->where('external_id', '4805')
            ->where('permission_status', 'testing_only')
            ->whereHas('integration', fn ($query) => $query
                ->where('venue_id', $court->venue_id)
                ->where('mode', 'test'))
            ->whereHas('courtMappings', fn ($query) => $query
                ->where('venue_court_id', $court->id))
            ->orderBy('priority')
            ->first();

        if ($source === null) {
            return null;
        }

        $snapshot = ExternalAvailabilitySnapshot::query()
            ->where('venue_integration_source_id', $source->id)
            ->where('venue_court_id', $court->id)
            ->where('venue_id', $court->venue_id)
            ->where('provider', 'FindSport')
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();

        if ($snapshot === null) {
            return null;
        }

        $dates = [$today->toDateString(), $today->addDays(8)->toDateString()];
        $slots = $snapshot->slots()
            ->whereBetween('local_date', $dates)
            ->orderBy('starts_at')
            ->get();

        $days = [];
        foreach ($slots as $slot) {
            $date = $slot->local_date->format('Y-m-d');
            $days[$date][] = [
                'starts_at' => $slot->starts_at->setTimezone($snapshot->timezone)->format('H:i'),
                'ends_at' => $slot->ends_at->setTimezone($snapshot->timezone)->format('H:i'),
                'status' => $slot->status,
                'price_minor' => $slot->price_minor,
                'currency' => $slot->currency,
                'day_complete' => (bool) $slot->day_complete,
            ];
        }

        return [
            'source_id' => $source->id,
            'snapshot_id' => $snapshot->id,
            'observed_at' => $snapshot->observed_at->setTimezone($snapshot->timezone)->format('d.m.Y H:i'),
            'stale' => $snapshot->isStale(),
            'timezone' => $snapshot->timezone,
            'days' => $days,
        ];
    }
}
