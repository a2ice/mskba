<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\VenueBooking;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Application\Services\EffectiveVenueHours;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueIntegration\Application\Services\AppliedImportedCourtAvailability;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class VenueEventAvailability
{
    public function __construct(
        private readonly EffectiveVenueHours $hours,
        private readonly AppliedImportedCourtAvailability $importedAvailability,
    ) {}

    public function resolveEndsAt(
        Venue $venue,
        CarbonImmutable $startsAt,
        ?int $durationMinutes = null,
        ?int $excludedBookingId = null,
        ?VenueCourt $court = null,
    ): CarbonImmutable {
        $court = $this->resolveCourt($venue, $court);

        if ($durationMinutes !== null) {
            $endsAt = $startsAt->addMinutes($durationMinutes);
            $this->assertAvailable($venue, $startsAt, $endsAt, $excludedBookingId, court: $court);

            return $endsAt;
        }

        $schedule = $venue->relationLoaded('schedule')
            ? $venue->schedule
            : $venue->schedule()->with(['intervals', 'exceptions.intervals'])->first();
        $timezone = $schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
        $localStart = $startsAt->setTimezone($timezone);
        $endBoundary = $localStart->endOfDay();
        $applicableIntervals = $this->hours->intervals($venue, $court, $localStart);

        if ($applicableIntervals !== null && $applicableIntervals->isEmpty()) {
            throw new InvalidArgumentException('В выбранную дату площадка закрыта.');
        }

        if ($applicableIntervals !== null) {
            $startTime = $localStart->format('H:i:s');
            $workingInterval = $applicableIntervals->first(
                fn ($interval): bool => $startTime >= $this->normalizeTime((string) $interval->starts_at)
                    && $startTime < $this->normalizeTime((string) $interval->ends_at)
            );

            if ($workingInterval === null) {
                throw new InvalidArgumentException('Выбранное время не входит в часы работы площадки.');
            }

            $endBoundary = CarbonImmutable::parse(
                $localStart->format('Y-m-d').' '.(string) $workingInterval->ends_at,
                $timezone,
            );
        }

        $nextBookingStart = VenueBooking::query()
            ->where('venue_id', $venue->id)
            ->when($court !== null, fn ($query) => $query->where(function ($query) use ($court): void {
                $query->whereNull('venue_court_id')->orWhere('venue_court_id', $court->id);
            }))
            ->when(
                $excludedBookingId !== null,
                fn ($query) => $query->whereKeyNot($excludedBookingId),
            )
            ->whereIn('status', VenueBookingStatusEnum::occupyingValues())
            ->where('ends_at', '>', $startsAt)
            ->orderBy('starts_at')
            ->value('starts_at');

        if ($nextBookingStart !== null) {
            $bookingBoundary = CarbonImmutable::parse($nextBookingStart)->setTimezone($timezone);

            if ($bookingBoundary->lessThanOrEqualTo($localStart)) {
                throw new InvalidArgumentException('Выбранное время уже занято другим мероприятием.');
            }

            if ($bookingBoundary->lessThan($endBoundary)) {
                $endBoundary = $bookingBoundary;
            }
        }

        $endsAt = $endBoundary->startOfMinute();

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new InvalidArgumentException('После выбранного времени нет свободного интервала.');
        }

        $this->assertAvailable($venue, $startsAt, $endsAt, $excludedBookingId, court: $court);

        return $endsAt;
    }

    public function assertAvailable(
        Venue $venue,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?int $excludedBookingId = null,
        bool $checkBookings = true,
        VenueBookingScopeEnum $scope = VenueBookingScopeEnum::WHOLE,
        ?VenueCourt $court = null,
        bool $allowEndAtMidnight = false,
    ): void {
        $court = $this->resolveCourt($venue, $court);

        if ($scope !== VenueBookingScopeEnum::WHOLE) {
            $supportsHalves = $court !== null
                ? $court->supports_halves
                : (int) $venue->characteristics()->value('hoops_count') >= 2;

            if (! $supportsHalves) {
                throw new InvalidArgumentException('Выбранный зал не поддерживает бронирование отдельных половин.');
            }
        }

        if ($venue->status !== VenueStatusEnum::CONFIRMED) {
            throw new InvalidArgumentException('Создать мероприятие можно только на подтверждённой площадке.');
        }

        if ($venue->operational_status !== VenueOperationalStatusEnum::ACTIVE) {
            throw new InvalidArgumentException('Площадка временно закрыта.');
        }
        if ($court?->operational_status === VenueOperationalStatusEnum::TEMPORARILY_CLOSED) {
            throw new InvalidArgumentException('Выбранный зал временно закрыт.');
        }

        $minimumStartsAt = CarbonImmutable::now($startsAt->getTimezone())
            ->subMinute()
            ->startOfMinute();
        if ($startsAt->lessThan($minimumStartsAt)) {
            throw new InvalidArgumentException('Начало не может быть раньше текущего времени.');
        }

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new InvalidArgumentException('Время окончания должно быть позже времени начала.');
        }

        $schedule = $venue->relationLoaded('schedule')
            ? $venue->schedule
            : $venue->schedule()->with(['intervals', 'exceptions.intervals'])->first();

        $timezone = $schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
        $localStart = $startsAt->setTimezone($timezone);
        $localEnd = $endsAt->setTimezone($timezone);

        // 00:00 on the following day is the exclusive end of the starting
        // date, not an overnight stay. Rental requests may finish exactly
        // here; event creation retains its existing same-day restriction.
        $endsAtMidnight = $localEnd->equalTo($localStart->startOfDay()->addDay());
        if (! $localStart->isSameDay($localEnd)
            && ! ($allowEndAtMidnight && $endsAtMidnight)) {
            throw new InvalidArgumentException($allowEndAtMidnight
                ? 'Аренда должна завершаться не позднее 00:00 следующих суток.'
                : 'В первой версии мероприятие должно завершаться в тот же день.');
        }

        $overlaps = $checkBookings ? VenueBooking::query()
            ->where('venue_id', $venue->id)
            ->when($court !== null, fn ($query) => $query->where(function ($query) use ($court): void {
                $query->whereNull('venue_court_id')->orWhere('venue_court_id', $court->id);
            }))
            ->when(
                $excludedBookingId !== null,
                fn ($query) => $query->whereKeyNot($excludedBookingId),
            )
            ->whereIn('status', VenueBookingStatusEnum::occupyingValues())
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->where(function ($query) use ($scope): void {
                $query->whereNull('scope')->orWhereIn('scope', $scope->conflictingValues());
            })
            ->orderBy('starts_at')
            ->get(['starts_at', 'ends_at']) : collect();

        if ($overlaps->isNotEmpty()) {
            $cursor = $startsAt;
            foreach ($overlaps as $booking) {
                $from = CarbonImmutable::parse($booking->starts_at);
                $until = CarbonImmutable::parse($booking->ends_at);
                if ($from->greaterThan($cursor)) {
                    break;
                }
                if ($until->greaterThan($cursor)) {
                    $cursor = $until;
                }
            }
            throw new InvalidArgumentException($cursor->greaterThanOrEqualTo($endsAt)
                ? 'Выбранное время уже занято.'
                : 'Часть выбранного времени уже занята.');
        }

        $applicableIntervals = $this->hours->intervals($venue, $court, $localStart);

        if ($applicableIntervals !== null && $applicableIntervals->isEmpty()) {
            throw new InvalidArgumentException('В выбранную дату площадка закрыта.');
        }

        if ($applicableIntervals !== null) {
            $startTime = $localStart->format('H:i:s');
            $endTime = $allowEndAtMidnight && $endsAtMidnight
                ? '24:00:00' : $localEnd->format('H:i:s');
            // The native FindSport importer stores 23:59 in HTML time
            // fields as a sentinel for an actual midnight close. Only when
            // checking a rental that ends exactly at midnight, interpret
            // that sentinel as the exclusive 24:00 boundary. An ordinary
            // manually configured 23:59 closing time is not extended.
            $importedMidnight = $allowEndAtMidnight && $endsAtMidnight
                && $court !== null
                && $this->importedAvailability->activeFor($court) !== null;
            $insideWorkingInterval = $applicableIntervals->contains(
                function ($interval) use ($startTime, $endTime, $importedMidnight): bool {
                    $endBoundary = $this->normalizeTime((string) $interval->ends_at);
                    if ($importedMidnight && $endBoundary === '23:59:00') {
                        $endBoundary = '24:00:00';
                    }

                    return $startTime >= $this->normalizeTime((string) $interval->starts_at)
                        && $endTime <= $endBoundary;
                }
            );

            if (! $insideWorkingInterval) {
                throw new InvalidArgumentException('Выбранное время не входит в часы работы площадки.');
            }
        }

        if ($court !== null) {
            $this->importedAvailability->assertAvailable($court, $startsAt, $endsAt);
        }


    }

    private function resolveCourt(Venue $venue, ?VenueCourt $court): ?VenueCourt
    {
        if ($court !== null) {
            if ($court->venue_id !== $venue->id || $court->trashed()) {
                throw new InvalidArgumentException('Выбранный зал не относится к этой площадке.');
            }

            return $court;
        }

        return $venue->primaryCourt()->first() ?? $venue->courts()->first();
    }

    private function normalizeTime(string $value): string
    {
        return strlen($value) === 5 ? $value.':00' : $value;
    }
}
