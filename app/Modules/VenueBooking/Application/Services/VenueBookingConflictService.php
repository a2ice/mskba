<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
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
        if ($candidate->scope !== VenueBookingScopeEnum::WHOLE
            && (int) $venue->characteristics()->value('hoops_count') < 2) {
            throw new VenueBookingTransitionException(
                'Площадка больше не поддерживает аренду отдельных половин.',
                'BOOKING_SCOPE_UNAVAILABLE',
            );
        }

        $conflicts = $this->conflictQuery(
            $candidate->venue_id,
            $candidate->starts_at,
            $candidate->ends_at,
            $candidate->scope,
            $candidate->id,
        )->orderBy('id')->lockForUpdate()->get(['id', 'starts_at', 'ends_at', 'scope']);

        if ($conflicts->isEmpty()) {
            return;
        }

        $this->metrics->record($candidate, $conflicts->count());

        throw new VenueBookingConflictException($this->suggestions($venue, $candidate, $conflicts));
    }

    private function conflictQuery(
        int $venueId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        VenueBookingScopeEnum $scope,
        ?int $excludedBookingId = null,
    ): Builder {
        return VenueBooking::query()
            ->where('venue_id', $venueId)
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
    private function suggestions(Venue $venue, VenueBooking $candidate, Collection $conflicts): array
    {
        $duration = (int) $candidate->starts_at->diffInMinutes($candidate->ends_at);

        return $conflicts->pluck('ends_at')
            ->map(fn (CarbonImmutable $start): CarbonImmutable => $start)
            ->unique(fn (CarbonImmutable $start): string => $start->toIso8601String())
            ->sort()
            ->filter(function (CarbonImmutable $start) use ($venue, $candidate, $duration): bool {
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
