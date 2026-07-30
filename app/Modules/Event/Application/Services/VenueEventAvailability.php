<?php

namespace App\Modules\Event\Application\Services;

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
    ): void {
        if ($venue->status !== VenueStatusEnum::CONFIRMED) {
            throw new InvalidArgumentException('Создать мероприятие можно только на подтверждённой площадке.');
        }

        if ($venue->operational_status !== VenueOperationalStatusEnum::ACTIVE) {
            throw new InvalidArgumentException('Площадка временно закрыта.');
        }

        if ($startsAt->lessThanOrEqualTo(now())) {
            throw new InvalidArgumentException('Мероприятие должно начинаться в будущем.');
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
