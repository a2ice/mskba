<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingConflictException;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class VenueBookingConflictService
{
    public function __construct(
        private VenueBookingConflictMetrics $metrics,
        private VenueEventAvailability $availability,
    ) {}

    public function lockAndAssertAvailable(Venue $venue, VenueBooking $candidate): void
    {
        $court = $candidate->venue_court_id === null
            ? null
            : VenueCourt::query()
                ->where('venue_id', $venue->id)
                ->whereKey((int) $candidate->venue_court_id)
                ->first();

        if ($candidate->venue_court_id !== null && $court === null) {
            throw new VenueBookingTransitionException(
                'Выбранный зал больше недоступен.',
                'BOOKING_COURT_UNAVAILABLE',
            );
        }

        if ($candidate->scope !== VenueBookingScopeEnum::WHOLE) {
            $supportsHalves = $court !== null
                ? (bool) $court->supports_halves
                : (int) $venue->characteristics()->value('hoops_count') >= 2;

            if (! $supportsHalves) {
                throw new VenueBookingTransitionException(
                    'Зал больше не поддерживает аренду отдельных половин.',
                    'BOOKING_SCOPE_UNAVAILABLE',
                );
            }
        }

        $conflicts = $this->conflictQuery(
            $candidate->venue_id,
            $candidate->starts_at,
            $candidate->ends_at,
            $candidate->scope,
            $candidate->id,
            $candidate->venue_court_id,
        )->orderBy('id')->lockForUpdate()->get(['id', 'starts_at', 'ends_at', 'scope', 'venue_court_id']);

        if ($conflicts->isEmpty()) {
            return;
        }

        $this->metrics->record($candidate, $conflicts->count());

        throw new VenueBookingConflictException($this->suggestions($venue, $candidate, $conflicts, $court));
    }

    private function conflictQuery(
        int $venueId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        VenueBookingScopeEnum $scope,
        ?int $excludedBookingId = null,
        ?int $courtId = null,
    ): Builder {
        return VenueBooking::query()
            ->where('venue_id', $venueId)
            ->when($courtId !== null, fn (Builder $query) => $query->where(function (Builder $query) use ($courtId): void {
                $query->whereNull('venue_court_id')->orWhere('venue_court_id', $courtId);
            }))
            ->when($excludedBookingId !== null, fn (Builder $query) => $query->whereKeyNot($excludedBookingId))
            ->whereIn('status', VenueBookingStatusEnum::occupyingValues())
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->where(function (Builder $query) use ($scope): void {
                $query->whereNull('scope')->orWhereIn('scope', $scope->conflictingValues());
            });
    }

    /** @param Collection<int, VenueBooking> $conflicts
     * @return list<string>
     */
    private function suggestions(
        Venue $venue,
        VenueBooking $candidate,
        Collection $conflicts,
        ?VenueCourt $court,
    ): array {
        $duration = (int) $candidate->starts_at->diffInMinutes($candidate->ends_at);

        return $conflicts->pluck('ends_at')
            ->map(fn (CarbonImmutable $start): CarbonImmutable => $start)
            ->unique(fn (CarbonImmutable $start): string => $start->toIso8601String())
            ->sort()
            ->filter(function (CarbonImmutable $start) use ($venue, $candidate, $duration, $court): bool {
                $timezone = $venue->schedule()->value('timezone') ?: config('app.timezone', 'UTC');
                $localStart = $start->setTimezone($timezone);
                $step = (int) data_get($candidate->quote_snapshot, 'policy.time_step_minutes', 1);
                $minutesFromDayStart = $localStart->hour * 60 + $localStart->minute;

                if ($step < 1 || $minutesFromDayStart % $step !== 0) {
                    return false;
                }

                try {
                    $this->availability->assertAvailable(
                        $venue,
                        $start,
                        $start->addMinutes($duration),
                        $candidate->id,
                        scope: $candidate->scope,
                        court: $court,
                    );
                } catch (InvalidArgumentException) {
                    return false;
                }

                return true;
            })
            ->take(3)
            ->map(fn (CarbonImmutable $start): string => $start->utc()->toIso8601String())
            ->values()
            ->all();
    }
}
