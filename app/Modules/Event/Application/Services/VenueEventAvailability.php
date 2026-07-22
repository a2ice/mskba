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
    public function assertAvailable(Venue $venue, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
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

        $schedule = $venue->schedule()->with(['intervals', 'exceptions.intervals'])->first();

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
                fn ($interval): bool => $startTime >= (string) $interval->starts_at
                    && $endTime <= (string) $interval->ends_at
            );

            if (! $insideWorkingInterval) {
                throw new InvalidArgumentException('Выбранное время не входит в часы работы площадки.');
            }
        }

        $hasOverlap = VenueBooking::query()
            ->where('venue_id', $venue->id)
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
}
