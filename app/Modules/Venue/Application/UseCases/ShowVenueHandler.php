<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Application\Services\AddressDisplayFormatter;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Venue\Application\DTO\VenueAboutDTO;
use App\Modules\Venue\Application\DTO\VenueAddressDTO;
use App\Modules\Venue\Application\DTO\VenueAmenityDTO;
use App\Modules\Venue\Application\DTO\VenueDetailsDTO;
use App\Modules\Venue\Application\DTO\VenueMetroStationDTO;
use App\Modules\Venue\Application\DTO\VenueReviewDTO;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Exceptions\VenueAccessDeniedException;
use App\Modules\Venue\Domain\Exceptions\VenueNotFoundException;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueReview;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ShowVenueHandler
{
    public function __construct(
        private readonly VenueAccessResolver $access,
        private readonly AddressDisplayFormatter $addressFormatter,
    ) {}

    public function handle(string $alias, ?User $user, ?Actor $actor = null): VenueDetailsDTO
    {
        $venues = Venue::query()
            ->with([
                'creatorActor',
                'location.address',
                'location.metroStations.line',
                'media' => fn ($query) => $query
                    ->where('collection', 'gallery')
                    ->orderByDesc('is_featured')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'amenities' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('name'),
                'schedule.intervals',
                'schedule.exceptions.intervals',
                'reviews' => fn ($query) => $query
                    ->where('is_published', true)
                    ->with('user.profile')
                    ->orderByDesc('published_at')
                    ->orderByDesc('id')
                    ->limit(3),
            ])
            ->withCount([
                'reviews as published_reviews_count' => fn ($query) => $query->where('is_published', true),
            ])
            ->withAvg([
                'reviews as published_reviews_avg_rating' => fn ($query) => $query->where('is_published', true),
            ], 'rating')
            ->whereRouteIdentifier($alias)
            ->orderBy('id')
            ->get();

        if ($venues->isEmpty()) {
            throw new VenueNotFoundException;
        }

        $venue = $venues
            ->sortByDesc(fn (Venue $venue): int => $this->ownershipPriority($venue, $user, $actor))
            ->first(fn (Venue $venue): bool => $this->access->canView($user, $venue, $actor));

        if ($venue === null) {
            throw new VenueAccessDeniedException;
        }
        if (! $this->access->canView($user, $venue, $actor)) {
            throw new VenueAccessDeniedException;
        }

        $address = $venue->location?->address;
        $displayAddress = $this->displayAddress($address, $venue->raw_address);
        $metroStations = ($venue->location?->metroStations ?? collect())
            ->map(fn (MetroStation $station) => new VenueMetroStationDTO(
                id: (int) $station->id,
                name: $station->name,
                lineName: $station->line?->name,
                lineColor: $station->line?->color,
                latitude: $station->latitude === null ? null : (string) $station->latitude,
                longitude: $station->longitude === null ? null : (string) $station->longitude,
                distanceMeters: $station->pivot?->distance_meters === null ? null : (int) $station->pivot->distance_meters,
                walkingTimeMinutes: $station->pivot?->walking_time_minutes === null ? null : (int) $station->pivot->walking_time_minutes,
            ))
            ->values()
            ->all();
        $featuredMedia = $venue->media
            ->take(8)
            ->map(fn (Media $media) => [
                'id' => (int) $media->id,
                'title' => $media->title,
                'description' => $media->description,
                'url' => $media->publicUrl(),
                'isFeatured' => (bool) $media->is_featured,
            ])
            ->values()
            ->all();
        $amenities = $venue->amenities
            ->map(fn ($amenity) => new VenueAmenityDTO(
                id: (int) $amenity->id,
                name: $amenity->name,
                alias: $amenity->alias,
                description: $amenity->description,
                icon: $amenity->icon,
                note: $amenity->pivot?->note,
            ))
            ->values()
            ->all();
        $scheduleDays = $this->scheduleDays($venue);
        $openingState = $this->openingState($venue);
        $timezone = (string) ($venue->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow'));
        $occupiedSlots = $venue->bookings()
            ->whereIn('status', [
                VenueBookingStatusEnum::PENDING->value,
                VenueBookingStatusEnum::CONFIRMED->value,
            ])
            ->where('ends_at', '>', now())
            ->with('event')
            ->orderBy('starts_at')
            ->limit(5)
            ->get()
            ->map(function ($booking) use ($timezone): array {
                $startsAt = $booking->starts_at->setTimezone($timezone);
                $endsAt = $booking->ends_at->setTimezone($timezone);
                $event = $booking->event;
                $isPublicEvent = $event !== null
                    && $event->status === EventStatusEnum::PUBLISHED
                    && $event->visibility === EventVisibilityEnum::PUBLIC;

                return [
                    'label' => $startsAt->format('d.m.Y H:i').'–'.$endsAt->format('H:i'),
                    'eventTitle' => $isPublicEvent ? $event->title : null,
                    'eventUrl' => $isPublicEvent ? route('events.show', $event->routeIdentifier()) : null,
                ];
            })
            ->values()
            ->all();
        $reviews = $venue->reviews
            ->map(fn (VenueReview $review) => new VenueReviewDTO(
                id: (int) $review->id,
                rating: (int) $review->rating,
                body: $review->body,
                authorName: $this->reviewAuthorName($review),
                publishedAt: $review->published_at === null ? null : $this->russianDateWithYear($review->published_at),
            ))
            ->values()
            ->all();
        $sections = [
            ['id' => 'address', 'label' => 'Адрес', 'isAvailable' => $displayAddress !== ''],
            ['id' => 'amenities', 'label' => 'Опции', 'isAvailable' => $amenities !== []],
            ['id' => 'schedule', 'label' => 'Расписание', 'isAvailable' => $scheduleDays !== []],
            ['id' => 'posts', 'label' => 'Посты', 'isAvailable' => false],
            ['id' => 'reviews', 'label' => 'Отзывы', 'isAvailable' => $reviews !== []],
        ];

        if ($featuredMedia !== []) {
            array_unshift($sections, ['id' => 'gallery', 'label' => 'Галерея', 'isAvailable' => true]);
        }

        return new VenueDetailsDTO(
            id: $venue->id,
            name: $venue->name,
            alias: $venue->alias,
            type: $venue->type->label(),
            typeSlug: $venue->type->publicSlug(),
            status: $venue->status->label(),
            statusSlug: $venue->status->value,
            isOpen: $openingState['isOpen'],
            todayHours: $openingState['todayHours'],
            shortDescription: $venue->short_description,
            fullDescription: $venue->full_description,
            rawAddress: $venue->raw_address,
            address: $address === null ? null : new VenueAddressDTO(
                city: $address->city,
                street: $address->street,
                building: $address->building,
                postalCode: $address->postal_code,
                latitude: $address->latitude === null ? null : (string) $address->latitude,
                longitude: $address->longitude === null ? null : (string) $address->longitude,
                fullAddress: $address->full_address,
                display: $displayAddress,
            ),
            metroStations: $metroStations,
            about: new VenueAboutDTO(
                rating: $venue->published_reviews_avg_rating === null
                    ? null
                    : round((float) $venue->published_reviews_avg_rating, 1),
                ratingCount: (int) $venue->published_reviews_count ?: null,
                scheduleDays: $scheduleDays,
                scheduleUrl: null,
                feedUrl: null,
                bookingUrl: null,
                mapApiKey: config('integrations.yandex.api_key'),
            ),
            sections: $sections,
            amenities: $amenities,
            featuredMedia: $featuredMedia,
            reviews: $reviews,
            occupiedSlots: $occupiedSlots,
            canEdit: $this->access->canEdit($user, $venue, $actor),
            canEditSchedule: $this->access->canEditSchedule($user, $venue, $actor),
            canRemove: $this->access->canRemove($user, $venue, $actor),
        );
    }

    private function ownershipPriority(Venue $venue, ?User $user, ?Actor $actor): int
    {
        $creator = $venue->creatorActor;

        if ($creator === null) {
            return 0;
        }

        if ($actor !== null && $venue->created_by_actor_id === $actor->id) {
            return 4;
        }

        if ($user !== null && $creator->user_id === $user->id) {
            return 3;
        }

        if ($actor?->user_id !== null && $creator->user_id === $actor->user_id) {
            return 2;
        }

        return 0;
    }

    private function displayAddress(?Address $address, ?string $rawAddress): string
    {
        return $this->addressFormatter->format(
            $address?->full_address ?? $rawAddress,
            $address?->city,
            $address?->street,
            $address?->building,
        ) ?? '';
    }

    /**
     * @return array<int, array{date: string, label: string, weekday: string, isToday: bool, isClosed: bool, intervals: array<int, array{startsAt: string, endsAt: string}>}>
     */
    private function scheduleDays(Venue $venue): array
    {
        $schedule = $venue->schedule;

        if ($schedule === null || ($schedule->intervals->isEmpty() && $schedule->exceptions->isEmpty())) {
            return [];
        }

        $today = CarbonImmutable::now($schedule->timezone ?: config('app.timezone', 'UTC'))->startOfDay();
        $days = [];

        for ($offset = 0; $offset < 14; $offset++) {
            $date = $today->addDays($offset);
            $intervals = $this->intervalsForDate($venue, $date)
                ->map(fn ($interval) => [
                    'startsAt' => $this->formatTime($interval->starts_at),
                    'endsAt' => $this->formatTime($interval->ends_at),
                ])
                ->values()
                ->all();

            $days[] = [
                'date' => $date->toDateString(),
                'label' => $this->russianDateLabel($date),
                'weekday' => $this->russianWeekdayLabel($date),
                'isToday' => $offset === 0,
                'isClosed' => $intervals === [],
                'intervals' => $intervals,
            ];
        }

        return $days;
    }

    /**
     * @return array{isOpen: bool, todayHours: string}
     */
    private function openingState(Venue $venue): array
    {
        $schedule = $venue->schedule;
        if ($schedule === null || ($schedule->intervals->isEmpty() && $schedule->exceptions->isEmpty())) {
            return [
                'isOpen' => $venue->operational_status === VenueOperationalStatusEnum::ACTIVE,
                'todayHours' => 'Не установлено',
            ];
        }

        $now = CarbonImmutable::now($schedule->timezone ?: config('app.timezone', 'UTC'));
        $todayIntervals = $this->intervalsForDate($venue, $now);

        if ($todayIntervals->isEmpty()) {
            return ['isOpen' => false, 'todayHours' => 'Закрыто'];
        }

        $isWithinWorkingHours = $todayIntervals->contains(function ($interval) use ($now): bool {
            $startsAt = $now->setTimeFromTimeString($this->formatTime($interval->starts_at));
            $endsAt = $now->setTimeFromTimeString($this->formatTime($interval->ends_at));

            return $now->greaterThanOrEqualTo($startsAt) && $now->lessThan($endsAt);
        });
        $hours = $todayIntervals
            ->map(fn ($interval): string => $this->formatTime($interval->starts_at).'–'.$this->formatTime($interval->ends_at))
            ->implode(', ');

        return [
            'isOpen' => $venue->operational_status === VenueOperationalStatusEnum::ACTIVE && $isWithinWorkingHours,
            'todayHours' => $hours,
        ];
    }

    private function intervalsForDate(Venue $venue, CarbonInterface $date)
    {
        $schedule = $venue->schedule;
        $exception = $schedule?->exceptions->first(
            fn ($item): bool => $item->date->toDateString() === $date->toDateString()
        );

        if ($exception !== null) {
            return $exception->is_closed ? collect() : $exception->intervals->values();
        }

        return $schedule?->intervals->where('day_of_week', $date->dayOfWeekIso)->values() ?? collect();
    }

    private function formatTime(mixed $time): string
    {
        if ($time instanceof CarbonInterface) {
            return $time->format('H:i');
        }

        return substr((string) $time, 0, 5);
    }

    private function russianDateLabel(CarbonInterface $date): string
    {
        return $date->format('d').' '.$this->russianMonthLabel($date);
    }

    private function russianDateWithYear(CarbonInterface $date): string
    {
        return $this->russianDateLabel($date).' '.$date->format('Y');
    }

    private function russianWeekdayLabel(CarbonInterface $date): string
    {
        return match ((int) $date->dayOfWeekIso) {
            1 => 'Пн',
            2 => 'Вт',
            3 => 'Ср',
            4 => 'Чт',
            5 => 'Пт',
            6 => 'Сб',
            7 => 'Вс',
        };
    }

    private function russianMonthLabel(CarbonInterface $date): string
    {
        return match ((int) $date->month) {
            1 => 'янв',
            2 => 'фев',
            3 => 'мар',
            4 => 'апр',
            5 => 'мая',
            6 => 'июн',
            7 => 'июл',
            8 => 'авг',
            9 => 'сен',
            10 => 'окт',
            11 => 'ноя',
            12 => 'дек',
        };
    }

    private function reviewAuthorName(VenueReview $review): string
    {
        $profile = $review->user?->profile;
        $name = trim(implode(' ', array_filter([
            $profile?->first_name,
            $profile?->last_name,
        ])));

        if ($name !== '') {
            return $name;
        }

        return $review->user?->username ?: 'Участник MSKBA';
    }
}
