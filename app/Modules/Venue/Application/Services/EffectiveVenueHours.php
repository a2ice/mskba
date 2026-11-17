<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Missing court override inherits the live parent schedule (no snapshot).
 * A parent-wide closure, including an explicitly closed day, always wins.
 */
final class EffectiveVenueHours
{
    /** @return Collection<int, object>|null Null means opening hours are unrestricted/unknown. */
    public function intervals(Venue $venue, ?VenueCourt $court, CarbonInterface $date): ?Collection
    {
        $parent = $venue->relationLoaded('schedule') ? $venue->schedule
            : $venue->schedule()->with(['intervals', 'exceptions.intervals'])->first();

        $parentException = $parent?->exceptions->first(
            fn ($item): bool => $item->date->toDateString() === $date->toDateString()
        );
        $parentHasHours = $parent?->intervals->isNotEmpty() ?? false;
        $parentControlsDate = $parentHasHours || $parentException !== null;
        if ($parentException?->is_closed) {
            return collect();
        }
        $parentIntervals = $parentException !== null
            ? $parentException->intervals->values()
            : $parent?->intervals->where('day_of_week', $date->dayOfWeekIso)->values();

        // A day when the complex itself is closed cannot be reopened by a court.
        if ($parentControlsDate && $parentIntervals->isEmpty()) {
            return collect();
        }

        $courtSchedule = $court === null ? null : ($court->relationLoaded('schedule')
            ? $court->schedule : $court->schedule()->first());

        if ($courtSchedule === null) {
            return $parentControlsDate ? $parentIntervals : null;
        }

        $dateString = $date->toDateString();
        $exceptionRows = collect($courtSchedule->exceptions ?? [])
            ->filter(fn ($row): bool => ($row['date'] ?? null) === $dateString);
        if ($exceptionRows->isNotEmpty()
            && $exceptionRows->contains(fn ($row): bool => (bool) ($row['is_closed'] ?? false))) {
            return collect();
        }

        $weekly = collect($courtSchedule->weekly_intervals ?? [])
            ->filter(fn ($row): bool => (int) ($row['day_of_week'] ?? 0) === $date->dayOfWeekIso);
        $ownIntervals = $this->intervalObjects($exceptionRows->isNotEmpty() ? $exceptionRows : $weekly);

        // A shortened parent-wide exception also restricts court-specific hours.
        if ($parentException !== null) {
            $intersection = collect();
            foreach ($ownIntervals as $ownInterval) {
                foreach ($parentIntervals as $parentInterval) {
                    $start = max(substr((string) $ownInterval->starts_at, 0, 5),
                        substr((string) $parentInterval->starts_at, 0, 5));
                    $end = min(substr((string) $ownInterval->ends_at, 0, 5),
                        substr((string) $parentInterval->ends_at, 0, 5));
                    if ($start < $end) {
                        $intersection->push((object) ['starts_at' => $start, 'ends_at' => $end]);
                    }
                }
            }

            return $intersection->sortBy('starts_at')->values();
        }

        return $ownIntervals;
    }

    public function isException(Venue $venue, ?VenueCourt $court, CarbonInterface $date): bool
    {
        $parent = $venue->relationLoaded('schedule') ? $venue->schedule
            : $venue->schedule()->with('exceptions')->first();
        $dateString = $date->toDateString();

        return ($parent?->exceptions->contains(
            fn ($item): bool => $item->date->toDateString() === $dateString
        ) ?? false) || ($court !== null && collect(($court->schedule?->exceptions ?? []))
            ->contains(fn ($row): bool => ($row['date'] ?? null) === $dateString));
    }

    /** @return Collection<int, object> */
    private function intervalObjects(Collection $rows): Collection
    {
        return $rows
            ->filter(fn ($row): bool => ! empty($row['starts_at']) && ! empty($row['ends_at'])
                && $row['starts_at'] < $row['ends_at'])
            ->map(fn ($row): object => (object) [
                'starts_at' => $row['starts_at'],
                'ends_at' => $row['ends_at'],
            ])
            ->sortBy('starts_at')
            ->values();
    }
}
