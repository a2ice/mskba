<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueIntegration\Application\Services\AppliedImportedCourtAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read-only occupancy percentages for the existing public schedule cards.
 *
 * Use the applied source (not the newest, possibly unapplied preview);
 * supplement it with MSKBA reservations. Unknown coverage has no percentage.
 * This never polls FindSport when rendering a public venue page.
 */
final readonly class VenueScheduleDayOccupancy
{
    public function __construct(private AppliedImportedCourtAvailability $imports) {}

    /**
     * @param array<int, array<string, mixed>> $days
     * @return array<string, int|null>
     */
    public function forDays(Venue $venue, ?VenueCourt $court, array $days, string $timezone): array
    {
        if ($days === []) {
            return [];
        }

        $court ??= $venue->courts->count() === 1 ? $venue->courts->first() : null;
        if ($court === null) {
            return array_fill_keys(array_column($days, 'date'), null);
        }

        $first = CarbonImmutable::parse($days[0]['date'], $timezone)->startOfDay();
        $last = CarbonImmutable::parse($days[array_key_last($days)]['date'], $timezone)
            ->addDay()->startOfDay();

        $bookings = $venue->bookings()
            ->where(function ($query) use ($court): void {
                $query->whereNull('venue_court_id')->orWhere('venue_court_id', $court->id);
            })
            ->whereIn('status', VenueBookingStatusEnum::occupyingValues())
            ->where('starts_at', '<', $last->utc())
            ->where('ends_at', '>', $first->utc())
            ->get(['starts_at', 'ends_at', 'venue_court_id']);

        $import = $this->imports->activeFor($court);
        $externalDays = $import === null ? null : $import->snapshot->slots()
            ->whereBetween('local_date', [$days[0]['date'], $days[array_key_last($days)]['date']])
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn ($slot): string => $slot->local_date->toDateString());

        $percentages = [];
        foreach ($days as $day) {
            $dayDate = $day['date'];
            $total = 0;
            $busy = 0;

            if ($externalDays !== null) {
                // A day outside the source's actual coverage is unknown,
                // not zero-percent occupied.
                foreach ($externalDays->get($dayDate, collect()) as $slot) {
                    if (! $slot->day_complete || ! in_array($slot->status, ['booked', 'available'], true)) {
                        continue;
                    }

                    $total++;
                    if ($slot->status === 'booked'
                        || $this->overlaps($bookings, $slot->starts_at, $slot->ends_at)) {
                        $busy++;
                    }
                }
            } else {
                // Native schedule: compute occupancy over actual open half-hour
                // cells, not an arbitrary 24-hour day.
                $date = CarbonImmutable::parse($dayDate, $timezone)->startOfDay();
                foreach ($day['intervals'] ?? [] as $hours) {
                    $open = $date->setTimeFromTimeString($hours['startsAt']);
                    $close = $hours['endsAt'] === '00:00'
                        ? $date->addDay() : $date->setTimeFromTimeString($hours['endsAt']);
                    for ($start = $open; $start->lessThan($close); $start = $start->addMinutes(30)) {
                        $end = $start->addMinutes(30)->min($close);
                        $total++;
                        if ($this->overlaps($bookings, $start, $end)) {
                            $busy++;
                        }
                    }
                }
            }

            $percentages[$dayDate] = $total > 0
                ? (int) round(100 * $busy / $total)
                : null;
        }

        return $percentages;
    }

    private function overlaps(Collection $bookings, \DateTimeInterface $start, \DateTimeInterface $end): bool
    {
        return $bookings->contains(static fn ($booking): bool =>
            $booking->starts_at->lessThan($end) && $booking->ends_at->greaterThan($start)
        );
    }
}
