<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Application\Queries\GetVenueAvailability;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Modules\VenueIntegration\Application\Services\AppliedImportedCourtAvailability;
use App\Modules\VenueIntegration\Application\Services\FindSportOnDemandRefresh;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * A day modal always renders current MSKBA availability; the applied
 * FindSport snapshot is a data source for that existing court schedule.
 */
final readonly class PublicVenueDaySchedule
{
    public function __construct(
        private EffectiveVenueHours $hours,
        private EffectiveCourtPolicy $policies,
        private AppliedImportedCourtAvailability $imports,
        private FindSportOnDemandRefresh $refresher,
        private GetVenueAvailability $availability,
    ) {}

    /** @return array<string, mixed> */
    public function forDate(Venue $venue, VenueCourt $court, string $date): array
    {
        if ($court->venue_id !== $venue->id || $court->trashed()) {
            throw new InvalidArgumentException('Указанный зал не принадлежит площадке.');
        }
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Europe/Moscow');
        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Некорректная дата.');
        }

        $today = CarbonImmutable::now('Europe/Moscow')->startOfDay();
        if ($day->lessThan($today) || $day->greaterThan($today->addDays(8))) {
            throw new InvalidArgumentException('Доступен просмотр текущего дня и следующих восьми дней.');
        }

        $refresh = $this->refresher->forCourt($court);
        $import = $this->imports->activeFor($court);
        $snapshot = $import?->snapshot;
        $stale = $snapshot !== null && ($snapshot->isStale()
            || (config('venue_integrations.public_schedule_refresh_enabled', false)
                && $snapshot->observed_at->lessThan(
                    now()->subSeconds(FindSportOnDemandRefresh::REFRESH_AFTER_SECONDS),
                )));
        $rangeStart = $day;
        $rangeEnd = $day->addDay();
        $projection = $this->availability->handle(
            $venue, $rangeStart->utc(), $rangeEnd->utc(), $court,
        );
        $busy = collect($projection['busy'] ?? []);
        $policy = VenueBookingPolicy::query()->where('venue_id', $venue->id)
            ->where('active_marker', true)->first();
        $effective = $this->policies->resolve($venue, $court, $policy);
        $step = (int) ($effective['time_step_minutes'] ?? 30);
        // The portal's current booking UI supports the 30/60-minute pilot.
        $supportedStep = in_array($step, [30, 60], true);
        $step = $supportedStep ? $step : 30;
        $minimum = max($step, (int) ($effective['minimum_duration_minutes'] ?? 60), (int) ($snapshot?->minimum_minutes ?? 0));
        $maximum = (int) ($effective['maximum_duration_minutes'] ?? 480);
        $leadTime = (int) ($effective['minimum_lead_time_minutes'] ?? 0);
        $rentalEnabled = $supportedStep
            && config('features.venue_rental.rental_flow', false)
            && $policy !== null && $policy->is_enabled
            && (bool) $effective['rental_enabled']
            && $court->allows_whole
            && $maximum >= $minimum
            && (int) $policy->time_step_minutes > 0
            && $step % (int) $policy->time_step_minutes === 0;

        $intervals = $this->hours->intervals($venue, $court, $day);
        $openings = ($intervals ?? collect())->map(static fn (object $row): array => [
            'from' => substr((string) $row->starts_at, 0, 5),
            'to' => substr((string) $row->ends_at, 0, 5),
        ])->values()->all();

        $sourceSlots = $snapshot === null ? collect() : $snapshot->slots()
            ->whereDate('local_date', $date)->orderBy('starts_at')->get();
        $byStart = $sourceSlots->keyBy(fn ($slot): string =>
            $slot->starts_at->setTimezone('Europe/Moscow')->format('H:i'),
        );
        $priceRows = $court->calendarSlotPrices()->whereDate('date', $date)->get()
            ->keyBy(fn ($row): string => substr((string) $row->starts_at, 0, 5));

        $minMinute = $openings !== [] ? min(array_map(static fn ($i): int =>
            (int) substr($i['from'], 0, 2) * 60 + (int) substr($i['from'], 3, 2), $openings)) : 7 * 60;
        $maxMinute = $openings !== [] ? max(array_map(static fn ($i): int =>
            (int) substr($i['to'], 0, 2) * 60 + (int) substr($i['to'], 3, 2), $openings)) : 24 * 60;
        // A current pilot snapshot may contain a booked interval outside
        // today's free-runs; include the whole source coverage in the modal.
        if ($snapshot !== null) {
            $minMinute = min($minMinute, 7 * 60);
            $maxMinute = max($maxMinute, 24 * 60);
        }

        $startIndex = intdiv($minMinute, $step);
        $endIndex = (int) ceil($maxMinute / $step);
        $slots = [];

        for ($index = $startIndex; $index < $endIndex; $index++) {
            $start = $day->addMinutes($index * $step);
            $end = $start->addMinutes($step);
            $startTime = $start->format('H:i');
            $endTime = $end->toDateString() === $date ? $end->format('H:i') : '24:00';
            $inHours = collect($openings)->contains(static fn (array $row): bool =>
                $startTime >= $row['from'] && $endTime <= $row['to']);

            $isBusy = $busy->contains(static function (array $entry) use ($start, $end): bool {
                try {
                    return CarbonImmutable::parse($entry['starts_at'])->lessThan($end)
                        && CarbonImmutable::parse($entry['ends_at'])->greaterThan($start);
                } catch (\Throwable) {
                    return true;
                }
            });

            $priceMinor = 0;
            $status = $inHours ? 'available' : 'unavailable';
            if ($snapshot !== null) {
                $parts = [];
                for ($minute = 0; $minute < $step; $minute += 30) {
                    $part = $byStart->get($start->addMinutes($minute)->format('H:i'));
                    $parts[] = $part;
                }
                if (in_array(null, $parts, true) || collect($parts)->contains(
                    fn ($part): bool => $part === null || ! $part->day_complete || $part->status === 'unknown'
                )) {
                    $status = 'unknown';
                } elseif (collect($parts)->contains(fn ($part): bool => $part->status === 'booked')) {
                    $status = 'booked';
                } elseif (collect($parts)->contains(fn ($part): bool => $part->status === 'unavailable')) {
                    $status = 'unavailable';
                }
                if (collect($parts)->every(fn ($part): bool => $part !== null && $part->price_minor !== null)) {
                    $priceMinor = (int) collect($parts)->sum('price_minor');
                } else {
                    $priceMinor = null;
                }
            } else {
                $priceMinor = $priceRows->get($startTime)?->whole_price_per_step_minor
                    ?? $court->whole_price_per_step_minor
                    ?? $policy?->whole_price_per_step_minor;
                $priceMinor = $priceMinor === null ? null : (int) $priceMinor;
            }

            if ($isBusy) {
                $status = 'booked';
            } elseif (! $inHours && $status === 'available') {
                $status = 'unavailable';
            }
            if ($snapshot === null && $intervals === null) {
                $status = 'unknown';
            }

            $inFuture = $start->greaterThanOrEqualTo(CarbonImmutable::now('Europe/Moscow')->addMinutes($leadTime));
            $selectable = $rentalEnabled && ! $stale && $inFuture
                && $status === 'available' && $priceMinor !== null;

            $slots[] = [
                'starts_at' => $startTime,
                'ends_at' => $endTime,
                'status' => $status,
                'selectable' => $selectable,
                'price_minor' => $priceMinor,
            ];
        }

        return [
            'date' => $date,
            'venue_court_id' => $court->id,
            'venue_court_name' => $court->publicName(),
            'timezone' => 'Europe/Moscow',
            'step_minutes' => $step,
            'minimum_minutes' => $minimum,
            'maximum_minutes' => $maximum,
            'booking_enabled' => $rentalEnabled,
            'last_updated_at' => $snapshot?->observed_at?->setTimezone('Europe/Moscow')->format('d.m.Y H:i:s'),
            'refresh_interval_seconds' => FindSportOnDemandRefresh::REFRESH_AFTER_SECONDS,
            'refresh_state' => $refresh['state'],
            'refresh_message' => $refresh['message'],
            'stale' => $stale,
            'slots' => $slots,
        ];
    }
}
