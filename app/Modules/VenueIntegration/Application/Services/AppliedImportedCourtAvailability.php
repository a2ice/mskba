<?php

namespace App\Modules\VenueIntegration\Application\Services;

use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueIntegration\Domain\Models\ExternalAvailabilitySlot;
use App\Modules\VenueIntegration\Domain\Models\VenueCourtExternalScheduleImport;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
            ->where('starts_at', '<', $endsAt->utc()->toIso8601String())
            ->where('ends_at', '>', $startsAt->utc()->toIso8601String())
            ->where('status', '!=', 'available')
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Imported court exceptions describe free runs, not opening hours.
     * Diagnose blocked time only when the source covers the entire selection;
     * otherwise the caller still decides whether it is outside working hours.
     */
    public function assertNotBlockedWithinCoverage(
        VenueCourt $court,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): void {
        $import = $this->activeFor($court);
        if ($import === null) {
            return;
        }

        $cursor = $startsAt->utc();
        $end = $endsAt->utc();
        $slots = $import->snapshot->slots()
            ->where('starts_at', '<', $end->toIso8601String())
            ->where('ends_at', '>', $cursor->toIso8601String())
            ->orderBy('starts_at')
            ->get();

        $booked = false;
        $allBooked = true;
        $unavailable = false;
        foreach ($slots as $slot) {
            if ($slot->starts_at->greaterThan($cursor) || ! $slot->day_complete) {
                return;
            }
            $booked = $booked || $slot->status === 'booked';
            $allBooked = $allBooked && $slot->status === 'booked';
            $unavailable = $unavailable || $slot->status !== 'available';
            if ($slot->ends_at->greaterThan($cursor)) {
                $cursor = $slot->ends_at->utc();
            }
            if ($cursor->greaterThanOrEqualTo($end)) {
                break;
            }
        }

        if ($cursor->lessThan($end) || ! $unavailable) {
            return;
        }

        $this->assertFresh($import, now());

        throw new InvalidArgumentException($booked
            ? ($allBooked ? 'Выбранное время уже занято.' : 'Выбранное время уже занято частично.')
            : 'Выбранное время недоступно по расписанию FindSport.');
    }

    private function assertFresh(VenueCourtExternalScheduleImport $import, CarbonInterface $reference): void
    {
        $snapshot = $import->snapshot;
        if ($snapshot->isStale($reference)
            || (config('venue_integrations.public_schedule_refresh_enabled', false)
                && $snapshot->observed_at->addSeconds(FindSportOnDemandRefresh::REFRESH_AFTER_SECONDS)
                    ->lessThan($reference))) {
            throw new InvalidArgumentException('Расписание FindSport устарело: сначала обновите и примените снимок.');
        }
    }

    public function assertAvailable(
        VenueCourt $court,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?CarbonInterface $asOf = null,
    ): void {
        $import = $this->activeFor($court);
        if ($import === null) {
            return;
        }

        $snapshot = $import->snapshot;
        $this->assertFresh($import, $asOf ?? now());

        $cursor = $startsAt->utc();
        $end = $endsAt->utc();
        $slots = $snapshot->slots()
            ->where('starts_at', '<', $end->toIso8601String())
            ->where('ends_at', '>', $cursor->toIso8601String())
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
