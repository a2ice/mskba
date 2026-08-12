<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\VenueBooking;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class VenueEventAvailability
{
    public function resolveEndsAt(
        Venue $venue,
        CarbonImmutable $startsAt,
        ?int $durationMinutes = null,
        ?int $excludedBookingId = null,
    ): CarbonImmutable {
        if ($durationMinutes !== null) {
            $endsAt = $startsAt->addMinutes($durationMinutes);
            $this->assertAvailable($venue, $startsAt, $endsAt, $excludedBookingId);

            return $endsAt;
        }

        $schedule = $venue->relationLoaded('schedule')
            ? $venue->schedule
            : $venue->schedule()->with(['intervals', 'exceptions.intervals'])->first();
        $timezone = $schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
        $localStart = $startsAt->setTimezone($timezone);
        $endBoundary = $localStart->endOfDay();
        $exception = $schedule?->exceptions->first(
            fn ($item): bool => $item->date->format('Y-m-d') === $localStart->format('Y-m-d')
        );

        if ($exception?->is_closed) {
            throw new InvalidArgumentException('В выбранную дату площадка закрыта.');
        }

        $hasRegularHours = $schedule?->intervals->isNotEmpty() ?? false;
        $applicableIntervals = $exception?->intervals
            ?? ($hasRegularHours
                ? $schedule->intervals->where('day_of_week', $localStart->isoWeekday())
                : null);

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
            ->when(
                $excludedBookingId !== null,
                fn ($query) => $query->whereKeyNot($excludedBookingId),
            )
            ->whereIn('status', [
                VenueBookingStatusEnum::PENDING->value,
                VenueBookingStatusEnum::CONFIRMED->value,
            ])
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

        $this->assertAvailable($venue, $startsAt, $endsAt, $excludedBookingId);

        return $endsAt;
    }

    public function assertAvailable(
        Venue $venue,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?int $excludedBookingId = null,
        bool $checkBookings = true,
        VenueBookingScopeEnum $scope = VenueBookingScopeEnum::WHOLE,
    ): void {
        if ($scope !== VenueBookingScopeEnum::WHOLE && (int) $venue->characteristics()->value('hoops_count') < 2) {
            throw new InvalidArgumentException('Выбранная площадка не поддерживает бронирование отдельных половин.');
        }

        if ($venue->status !== VenueStatusEnum::CONFIRMED) {
            throw new InvalidArgumentException('Создать мероприятие можно только на подтверждённой площадке.');
        }

        if ($venue->operational_status !== VenueOperationalStatusEnum::ACTIVE) {
            throw new InvalidArgumentException('Площадка временно закрыта.');
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

        if (! $localStart->isSameDay($localEnd)) {
            throw new InvalidArgumentException('В первой версии мероприятие должно завершаться в тот же день.');
        }

        $exception = $schedule?->exceptions->first(
            fn ($item): bool => $item->date->format('Y-m-d') === $localStart->format('Y-m-d')
        );

        if ($exception?->is_closed) {
            throw new InvalidArgumentException('В выбранную дату площадка закрыта.');
        }

        $hasRegularHours = $schedule?->intervals->isNotEmpty() ?? false;
        $applicableIntervals = $exception?->intervals
            ?? ($hasRegularHours
                ? $schedule->intervals->where('day_of_week', $localStart->isoWeekday())
                : null);

        if ($applicableIntervals !== null) {
            $startTime = $localStart->format('H:i:s');
            $endTime = $localEnd->format('H:i:s');
            $insideWorkingInterval = $applicableIntervals->contains(
                fn ($interval): bool => $startTime >= $this->normalizeTime((string) $interval->starts_at)
                    && $endTime <= $this->normalizeTime((string) $interval->ends_at)
            );

            if (! $insideWorkingInterval) {
                throw new InvalidArgumentException('Выбранное время не входит в часы работы площадки.');
            }
        }

        $hasOverlap = $checkBookings && VenueBooking::query()
            ->where('venue_id', $venue->id)
            ->when(
                $excludedBookingId !== null,
                fn ($query) => $query->whereKeyNot($excludedBookingId),
            )
            ->whereIn('status', [
                VenueBookingStatusEnum::PENDING->value,
                VenueBookingStatusEnum::CONFIRMED->value,
            ])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->where(function ($query) use ($scope): void {
                $query->where('scope', VenueBookingScopeEnum::WHOLE->value);
                if ($scope === VenueBookingScopeEnum::WHOLE) {
                    $query->orWhereIn('scope', [VenueBookingScopeEnum::HALF_A->value, VenueBookingScopeEnum::HALF_B->value]);
                } else {
                    $query->orWhere('scope', $scope->value);
                }
            })
            ->exists();

        if ($hasOverlap) {
            throw new InvalidArgumentException('Выбранное время уже занято другим мероприятием.');
        }
    }

    private function normalizeTime(string $value): string
    {
        return strlen($value) === 5 ? $value.':00' : $value;
    }
}
