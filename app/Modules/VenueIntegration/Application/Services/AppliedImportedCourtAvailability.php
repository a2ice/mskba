<?php

namespace App\Modules\VenueIntegration\Application\Services;

use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueIntegration\Domain\Models\ExternalAvailabilitySlot;
use App\Modules\VenueIntegration\Domain\Models\VenueCourtExternalScheduleImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Active imported FindSport occupancy for the corresponding MSKBA court.
 * A missing or stale slot is not a free booking; no fake events are created.
 */
final class AppliedImportedCourtAvailability
{
    public function activeFor(VenueCourt $court): ?VenueCourtExternalScheduleImport
    {
        if (! config('venue_integrations.apply_findsport_native_enabled', false)) {
            return null;
        }

        return VenueCourtExternalScheduleImport::query()
            ->where('venue_court_id', $court->id)
            ->where('venue_id', $court->venue_id)
            ->with('snapshot')
            ->first();
    }

    /** @return Collection<int, ExternalAvailabilitySlot> */
    public function blockingSlots(
        VenueCourtExternalScheduleImport $import,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): Collection {
        return $import->snapshot->slots()
            ->where('starts_at', '<', $endsAt->utc())
            ->where('ends_at', '>', $startsAt->utc())
            ->where('status', '!=', 'available')
            ->orderBy('starts_at')
            ->get();
    }

    public function assertAvailable(
        VenueCourt $court,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): void {
        $import = $this->activeFor($court);
        if ($import === null) {
            return;
        }

        $snapshot = $import->snapshot;
        if ($snapshot->isStale()) {
            throw new InvalidArgumentException('Расписание FindSport устарело: сначала обновите и примените снимок.');
        }

        $cursor = $startsAt->utc();
        $end = $endsAt->utc();
        $slots = $snapshot->slots()
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $cursor)
            ->orderBy('starts_at')
            ->get();

        foreach ($slots as $slot) {
            $from = $slot->starts_at->utc();
            $until = $slot->ends_at->utc();

            if ($from->greaterThan($cursor)) {
                throw new InvalidArgumentException('Для выбранного времени нет полного расписания FindSport.');
            }
            if ($slot->status !== 'available' || ! $slot->day_complete) {
                throw new InvalidArgumentException('Выбранное время занято или недоступно по расписанию FindSport.');
            }
            if ($until->greaterThan($cursor)) {
                $cursor = $until;
            }
            if ($cursor->greaterThanOrEqualTo($end)) {
                return;
            }
        }

        throw new InvalidArgumentException('Для выбранного времени нет полного расписания FindSport.');
    }
}
