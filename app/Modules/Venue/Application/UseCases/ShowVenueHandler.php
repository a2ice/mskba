<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\VenueBooking;
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
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\Venue\Domain\Models\VenueReview;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ShowVenueHandler
{
    public function __construct(
        private readonly VenueAccessResolver $access,
        private readonly AddressDisplayFormatter $addressFormatter,
    ) {}

    public function handle(
        string $alias,
        ?User $user,
        ?Actor $actor = null,
        ?string $courtIdentifier = null,
    ): VenueDetailsDTO {
        $venues = Venue::query()
            ->with([
                'creatorActor',
                'courts',
                'courts.media' => fn ($query) => $query
                    ->where('collection', 'gallery')
                    ->orderByDesc('is_featured')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'characteristics',
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

        $courts = $venue->courts->values();
        $selectedCourt = $this->resolveCourt($venue, $courts, $courtIdentifier);
        $selectedCourt->loadMissing([
            'media' => fn ($query) => $query
                ->where('collection', 'gallery')
                ->orderByDesc('is_featured')
                ->orderBy('sort_order')
                ->orderBy('id'),
        ]);
        $parentHoops = (int) ($venue->characteristics?->hoops_count ?? 0);
        $parentHoops = in_array($parentHoops, [1, 2], true) ? $parentHoops : null;
        $courtPayloads = $courts
            ->map(fn (VenueCourt $court): array => $this->courtPayload($court, $parentHoops))
            ->all();

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
        $mediaSource = $selectedCourt->media->isNotEmpty() ? $selectedCourt->media : $venue->media;
        $featuredMedia = $mediaSource
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
        $rentalPolicy = VenueBookingPolicy::query()
            ->where('venue_id', $venue->id)
            ->where('active_marker', true)
            ->where('is_enabled', true)
            ->first();
        $occupancyDays = $this->occupancyDays($venue, $selectedCourt, $timezone, $rentalPolicy, $user);
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
            ['id' => 'activities', 'label' => 'Игры и мероприятия', 'isAvailable' => true],
            ['id' => 'amenities', 'label' => 'Опции', 'isAvailable' => $amenities !== []],
            ['id' => 'schedule', 'label' => 'Расписание', 'isAvailable' => $scheduleDays !== []],
            ['id' => 'posts', 'label' => 'Посты', 'isAvailable' => false],
            ['id' => 'reviews', 'label' => 'Отзывы', 'isAvailable' => $reviews !== []],
        ];

        return new VenueDetailsDTO(
            id: $venue->id,
            name: $this->publicVenueName($venue->name),
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
            courts: $courtPayloads,
            selectedCourt: $this->courtPayload($selectedCourt, $parentHoops),
            amenities: $amenities,
            featuredMedia: $featuredMedia,
            reviews: $reviews,
            occupancyDays: $occupancyDays,
            rental: $rentalPolicy === null ? null : [
                'quoteUrl' => route('venues.rental.quote', $venue),
                'requestUrl' => route('account.venue-bookings.store'),
                'authenticated' => $user !== null,
                'confirmedAccount' => (bool) $user?->canonical()->isConfirmed(),
                'minimumDurationMinutes' => (int) $rentalPolicy->minimum_duration_minutes,
                'maximumDurationMinutes' => (int) $rentalPolicy->maximum_duration_minutes,
                'timeStepMinutes' => (int) $rentalPolicy->time_step_minutes,
                'currency' => $rentalPolicy->currency,
                'courtId' => (int) $selectedCourt->id,
                'scopes' => array_values(array_filter([
                    $selectedCourt->allows_whole ? ['value' => 'whole', 'label' => 'Весь зал'] : null,
                    $selectedCourt->supports_halves && $selectedCourt->allows_halves ? ['value' => 'half_a', 'label' => 'Половина A'] : null,
                    $selectedCourt->supports_halves && $selectedCourt->allows_halves ? ['value' => 'half_b', 'label' => 'Половина B'] : null,
                ])),
            ],
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

        $identityIds = $user?->canonical()->identityIds() ?? $actor?->user?->canonical()->identityIds() ?? [];

        if ($user !== null && in_array((int) $creator->user_id, $identityIds, true)) {
            return 3;
        }

        if ($actor?->user_id !== null && in_array((int) $creator->user_id, $identityIds, true)) {
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

    private function publicVenueName(string $name): string
    {
        $normalized = preg_replace('/\s*[-–—]\s*\d+\s+зал(?:а|ов)?\s*$/ui', '', trim($name));

        return is_string($normalized) && trim($normalized) !== '' ? trim($normalized) : $name;
    }

    private function resolveCourt(Venue $venue, $courts, ?string $identifier): VenueCourt
    {
        if ($identifier !== null && $identifier !== '') {
            $court = VenueCourt::query()
                ->where('venue_id', $venue->id)
                ->whereRouteIdentifier($identifier)
                ->first();

            if ($court === null) {
                throw new VenueNotFoundException;
            }

            return $court;
        }

        $court = $courts->first(fn (VenueCourt $item): bool => $item->is_primary)
            ?? $courts->first();

        if (! $court instanceof VenueCourt) {
            throw new VenueNotFoundException;
        }

        return $court;
    }

    /**
     * @return array{id: int, name: string, alias: string, routeIdentifier: string, isPrimary: bool, supportsHalves: bool, hoopsCount: ?int, surfaceType: ?string, surfaceLabel: ?string, allowsWhole: bool, allowsHalves: bool}
     */
    private function courtPayload(VenueCourt $court, ?int $parentHoops): array
    {
        $hoopsCount = $court->hoops_count;
        if ($hoopsCount === null) {
            $hoopsCount = $court->is_primary && in_array($parentHoops, [1, 2], true)
                ? $parentHoops
                : ($court->supports_halves ? 2 : 1);
        }

        return [
            'id' => (int) $court->id,
            'name' => $court->name,
            'alias' => $court->alias,
            'routeIdentifier' => $court->routeIdentifier(),
            'isPrimary' => (bool) $court->is_primary,
            'supportsHalves' => (bool) $court->supports_halves,
            'hoopsCount' => (int) $hoopsCount,
            'surfaceType' => $court->surface_type?->value,
            'surfaceLabel' => $court->surface_type?->label(),
            'allowsWhole' => (bool) $court->allows_whole,
            'allowsHalves' => (bool) $court->allows_halves,
        ];
    }

    /**
     * @return array<int, array{date: string, label: string, weekday: string, isToday: bool, state: string, slots: array<int, array{timeLabel: string, eventTypeLabel: string, eventUrl: ?string, statusIcon: string, statusLabel: string, status: string}>}>
     */
    private function occupancyDays(
        Venue $venue,
        VenueCourt $court,
        string $timezone,
        ?VenueBookingPolicy $policy,
        ?User $user,
    ): array {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $windowEnd = $today->addDays(9);
        $requesterUserId = $user?->canonical()->id;
        $bookings = $venue->bookings()
            ->where(function ($query) use ($court): void {
                $query->whereNull('venue_court_id')->orWhere('venue_court_id', $court->id);
            })
            ->where(function ($query) use ($requesterUserId): void {
                $query->whereIn('status', VenueBookingStatusEnum::occupyingValues());

                if ($requesterUserId !== null) {
                    $query->orWhere(function ($query) use ($requesterUserId): void {
                        $query
                            ->where('requester_user_id', $requesterUserId)
                            ->where('status', VenueBookingStatusEnum::REQUESTED->value);
                    });
                }
            })
            ->where('starts_at', '<', $windowEnd)
            ->where('ends_at', '>', $today)
            ->with('event.primaryGame')
            ->orderBy('starts_at')
            ->get();

        if ($bookings->isNotEmpty()) {
            $eventsByBookingId = Event::query()
                ->with('primaryGame')
                ->whereIn('booking_id', $bookings->pluck('id'))
                ->get()
                ->keyBy('booking_id');

            $bookings->each(function ($booking) use ($eventsByBookingId): void {
                if ($booking->getRelation('event') === null && $eventsByBookingId->has($booking->id)) {
                    $booking->setRelation('event', $eventsByBookingId->get($booking->id));
                }
            });
        }

        $days = [];

        for ($offset = 0; $offset < 9; $offset++) {
            $dayStart = $today->addDays($offset);
            $dayEnd = $dayStart->addDay();
            $dayBookings = $bookings
                ->filter(fn ($booking): bool => $booking->starts_at->setTimezone($timezone)->lessThan($dayEnd)
                    && $booking->ends_at->setTimezone($timezone)->greaterThan($dayStart))
                ->values();
            $hasConfirmed = $dayBookings->contains(
                fn ($booking): bool => $booking->status === VenueBookingStatusEnum::CONFIRMED
            );

            $days[] = [
                'date' => $dayStart->toDateString(),
                'label' => $this->russianDateLabel($dayStart),
                'weekday' => $this->russianWeekdayLabel($dayStart),
                'isToday' => $offset === 0,
                'state' => $hasConfirmed ? 'confirmed' : ($dayBookings->isNotEmpty() ? 'tentative' : 'free'),
                'slots' => $dayBookings
                    ->map(fn ($booking): array => $this->occupiedSlotData($booking, $timezone, $user))
                    ->all(),
                'timeline' => $this->occupancyTimeline($venue, $dayStart, $dayBookings, $timezone, $policy, $user),
            ];
        }

        return $days;
    }

    /**
     * @return array{timeLabel: string, eventTypeLabel: string, eventUrl: ?string, statusIcon: string, statusLabel: string, status: string}
     */
    private function occupiedSlotData(VenueBooking $booking, string $timezone, ?User $user = null): array
    {
        $startsAt = $booking->starts_at->setTimezone($timezone);
        $endsAt = $booking->ends_at->setTimezone($timezone);
        $event = $booking->event;
        $eventTypeLabel = $event !== null
            ? $this->occupiedSlotEventTypeLabel($event)
            : 'Бронирование';
        $statusMeta = $this->occupiedSlotStatusMeta($booking->status);
        $isPublicEvent = $event !== null
            && $event->status === EventStatusEnum::PUBLISHED
            && $event->visibility === EventVisibilityEnum::PUBLIC;

        return [
            'timeLabel' => $startsAt->format('H:i').'–'.$endsAt->format('H:i'),
            // Names are intentionally not exposed here: most are autogenerated,
            // while type/format is the useful compact descriptor for the slot.
            'eventTypeLabel' => $eventTypeLabel,
            'eventUrl' => $isPublicEvent ? route('events.show', $event->routeIdentifier()) : null,
            'statusIcon' => $statusMeta['icon'],
            'statusLabel' => $statusMeta['label'],
            'status' => $booking->status->value,
            'bookingId' => $booking->requester_user_id === $user?->canonical()->id ? $booking->public_id : null,
            'bookingUrl' => $booking->requester_user_id === $user?->canonical()->id
                ? route('account.venue-bookings.show', $booking)
                : null,
            'statusUrl' => $booking->requester_user_id === $user?->canonical()->id
                ? route('account.venue-bookings.status', $booking)
                : null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function occupancyTimeline(
        Venue $venue,
        CarbonImmutable $dayStart,
        $bookings,
        string $timezone,
        ?VenueBookingPolicy $policy,
        ?User $user,
    ): array {
        $intervals = $this->intervalsForDate($venue, $dayStart);
        if ($intervals->isEmpty()) {
            return $bookings->map(fn (VenueBooking $booking): array => [
                'kind' => 'occupied',
                'startsAt' => $booking->starts_at->setTimezone($timezone)->format('H:i'),
                'endsAt' => $booking->ends_at->setTimezone($timezone)->format('H:i'),
                'slots' => [$this->occupiedSlotData($booking, $timezone, $user)],
            ])->all();
        }

        $step = max(1, (int) ($policy?->time_step_minutes ?? 30));
        $now = CarbonImmutable::now($timezone);
        $timeline = [];

        foreach ($intervals as $interval) {
            $openMinute = $this->timeToMinutes((string) $interval->starts_at);
            $closeMinute = $this->timeToMinutes((string) $interval->ends_at);
            $ranges = $bookings->map(function (VenueBooking $booking) use ($dayStart, $timezone): array {
                $start = $booking->starts_at->setTimezone($timezone);
                $end = $booking->ends_at->setTimezone($timezone);

                return [
                    'start' => (int) max(0, $dayStart->diffInMinutes($start, false)),
                    'end' => (int) min(1440, $dayStart->diffInMinutes($end, false)),
                    'booking' => $booking,
                ];
            })->filter(fn (array $range): bool => $range['start'] < $closeMinute && $range['end'] > $openMinute)
                ->sortBy('start')->values();
            $groups = [];
            foreach ($ranges as $range) {
                $last = array_key_last($groups);
                if ($last !== null && $range['start'] < $groups[$last]['end']) {
                    $groups[$last]['end'] = max($groups[$last]['end'], $range['end']);
                    $groups[$last]['bookings'][] = $range['booking'];
                } else {
                    $groups[] = ['start' => $range['start'], 'end' => $range['end'], 'bookings' => [$range['booking']]];
                }
            }

            $cursor = (int) (ceil($openMinute / $step) * $step);
            foreach ($groups as $group) {
                $occupiedStart = max($openMinute, $group['start']);
                $occupiedEnd = min($closeMinute, $group['end']);
                $this->appendFreeTimelineCells($timeline, $dayStart, $cursor, $occupiedStart, $closeMinute, $step, $policy, $now);
                $timeline[] = [
                    'kind' => 'occupied',
                    'startsAt' => $this->minutesToTime($occupiedStart),
                    'endsAt' => $this->minutesToTime($occupiedEnd),
                    'slots' => collect($group['bookings'])
                        ->map(fn (VenueBooking $booking): array => $this->occupiedSlotData($booking, $timezone, $user))
                        ->all(),
                ];
                $cursor = max($cursor, $occupiedEnd);
            }
            $this->appendFreeTimelineCells($timeline, $dayStart, $cursor, $closeMinute, $closeMinute, $step, $policy, $now);
        }

        return $timeline;
    }

    /** @param array<int, array<string, mixed>> $timeline */
    private function appendFreeTimelineCells(
        array &$timeline,
        CarbonImmutable $dayStart,
        int $fromMinute,
        int $untilMinute,
        int $intervalEndMinute,
        int $step,
        ?VenueBookingPolicy $policy,
        CarbonImmutable $now,
    ): void {
        for ($minute = $fromMinute; $minute + $step <= $untilMinute; $minute += $step) {
            $startsAt = $dayStart->addMinutes($minute);
            $availableMinutes = max(0, min($untilMinute, $intervalEndMinute) - $minute);
            $bookable = $policy !== null
                && $availableMinutes >= $policy->minimum_duration_minutes
                && ! $startsAt->lessThan($now->addMinutes($policy->minimum_lead_time_minutes))
                && ! $startsAt->greaterThan($now->addDays($policy->maximum_advance_days));
            $timeline[] = [
                'kind' => 'free',
                'startsAt' => $startsAt->format('H:i'),
                'endsAt' => $startsAt->addMinutes($step)->format('H:i'),
                'availableMinutes' => $availableMinutes,
                'bookable' => $bookable,
            ];
        }
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }

    private function minutesToTime(int $minutes): string
    {
        $minutes = max(0, min(1440, $minutes));

        return $minutes === 1440
            ? '24:00'
            : sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function occupiedSlotEventTypeLabel(Event $event): string
    {
        $label = $event->type->label();

        if ($event->type === EventTypeEnum::GAME && $event->primaryGame !== null) {
            return $label.' '.$event->primaryGame->formatLabel();
        }

        return $label;
    }

    /** @return array{icon: string, label: string} */
    private function occupiedSlotStatusMeta(VenueBookingStatusEnum $status): array
    {
        return match ($status) {
            VenueBookingStatusEnum::CONFIRMED => ['icon' => '✅', 'label' => $status->label()],
            VenueBookingStatusEnum::PENDING => ['icon' => '🕒', 'label' => $status->label()],
            VenueBookingStatusEnum::HELD => ['icon' => '⏳', 'label' => $status->label()],
            default => ['icon' => '•', 'label' => $status->label()],
        };
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
