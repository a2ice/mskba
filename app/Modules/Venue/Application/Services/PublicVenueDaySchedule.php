<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Application\Queries\GetVenueAvailability;
use App\Modules\VenueBooking\Application\Services\VenuePricingRuleEvaluator;
use App\Modules\VenueBooking\Application\Services\VenueCommissionCalculator;
use App\Modules\VenueBooking\Application\Services\VenueSlotPricing;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Application\Services\EventIdentityParticipationService;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Illuminate\Support\Str;
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
        private VenuePricingRuleEvaluator $pricingRules,
        private VenueCommissionCalculator $commissions,
        private VenueSlotPricing $slotPricing,
        private EventIdentityParticipationService $eventParticipation,
        private VenueBookingAuthorization $bookingAuthorization,
    ) {}

    /** Configuration eligibility, independent of a particular day's free slots. */
    public function bookingEnabled(Venue $venue, VenueCourt $court, int $sourceMinimum = 0, ?VenueBookingPolicy $policy = null): bool
    {
        $policy ??= VenueBookingPolicy::query()->where('venue_id', $venue->id)
            ->where('active_marker', true)->first();
        $effective = $this->policies->resolve($venue, $court, $policy);
        $step = (int) ($effective['time_step_minutes'] ?? 30);
        $minimum = max($step, (int) ($effective['minimum_duration_minutes'] ?? 60), $sourceMinimum);
        $maximum = (int) ($effective['maximum_duration_minutes'] ?? 480);

        return in_array($step, [30, 60], true)
            && config('features.venue_rental.rental_flow', false)
            && $policy !== null && $policy->is_enabled
            && (bool) $effective['rental_enabled']
            && $court->allows_whole
            && $maximum >= $minimum
            && (int) $policy->time_step_minutes > 0
            && $step % (int) $policy->time_step_minutes === 0;
    }

    /** @return array<string, mixed> */
    public function forDate(
        Venue $venue, VenueCourt $court, string $date,
        ?string $previewStart = null, ?int $previewDurationMinutes = null,
        ?User $viewer = null,
        ?Actor $viewerActor = null,
    ): array
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
        // Group only single, fully covering MSKBA-owned reservations, not
        // arbitrary adjacent imported busy cells. Never return private data.
        $bookings = VenueBooking::query()
            ->where('venue_id', $venue->id)
            ->where(function ($query) use ($court): void {
                $query->whereNull('venue_court_id')->orWhere('venue_court_id', $court->id);
            })
            ->whereIn('status', VenueBookingStatusEnum::occupyingValues())
            ->where('starts_at', '<', $rangeEnd->utc())
            ->where('ends_at', '>', $rangeStart->utc())
            ->with([
                'event.organizerActor.user.profile',
                'event.participants',
                'requester',
                'transitions' => fn ($query) => $query
                    ->where('to_status', VenueBookingStatusEnum::CONFIRMED->value)
                    ->with('actor'),
            ])
            ->orderBy('starts_at')
            ->limit(500)
            ->get();
        $linkedEvents = $bookings->isEmpty() ? collect() : Event::query()
            ->whereIn('booking_id', $bookings->pluck('id'))
            ->with(['organizerActor.user.profile', 'participants'])
            ->get()
            ->keyBy('booking_id');
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
        $latestStart = CarbonImmutable::now('Europe/Moscow')->addDays((int) ($effective['maximum_advance_days'] ?? 90));
        $rentalEnabled = $this->bookingEnabled($venue, $court, (int) ($snapshot?->minimum_minutes ?? 0), $policy);

        $intervals = $this->hours->intervals($venue, $court, $day);
        $openings = ($intervals ?? collect())->map(static function (object $row) use ($snapshot): array {
            $endsAt = substr((string) $row->ends_at, 0, 5);
            // FindSport's native importer stores 23:59 as the HTML-time-safe
            // sentinel for an actual midnight close (24:00). Its last source
            // cell is 23:30–next-day 00:00, not 23:30–23:59.
            // Apply this only while an imported snapshot is active: ordinary
            // manually configured 23:59 closing times remain unchanged.
            if ($snapshot !== null && $endsAt === '23:59') {
                $endsAt = '24:00';
            }

            return [
                'from' => substr((string) $row->starts_at, 0, 5),
                'to' => $endsAt,
            ];
        })->values()->all();

        $sourceSlots = $snapshot === null ? collect() : $snapshot->slots()
            ->whereDate('local_date', $date)->orderBy('starts_at')->get();
        $byStart = $sourceSlots->keyBy(fn ($slot): string =>
            $slot->starts_at->setTimezone('Europe/Moscow')->format('H:i'),
        );
        // Fetch the whole native day in a single pricing resolution. This
        // includes inherited weekly and calendar tariff overrides exactly as
        // the authoritative booking quote does, without N queries per slot.
        $nativePrices = $policy !== null && $supportedStep
            ? $this->slotPricing->calculate(
                $venue, $policy, $day, 1440, VenueBookingScopeEnum::WHOLE, $court, $effective,
            )['prices_per_step_minor']
            : null;

        $minMinute = $openings !== [] ? min(array_map(static fn ($i): int =>
            (int) substr($i['from'], 0, 2) * 60 + (int) substr($i['from'], 3, 2), $openings)) : 7 * 60;
        $maxMinute = $openings !== [] ? max(array_map(static fn ($i): int =>
            (int) substr($i['to'], 0, 2) * 60 + (int) substr($i['to'], 3, 2), $openings)) : 24 * 60;
        // Use the actual imported FindSport source coverage, not Basketpoint's
        // former 07:00–24:00 grid. Fully booked days can have no open runs:
        // their earlier booked slots must still be shown from 00:00 onwards.
        if ($sourceSlots->isNotEmpty()) {
            $firstSourceTime = $sourceSlots->first()->starts_at
                ->setTimezone('Europe/Moscow');
            $lastSourceEnd = $sourceSlots->last()->ends_at
                ->setTimezone('Europe/Moscow');
            $firstMinute = $firstSourceTime->hour * 60 + $firstSourceTime->minute;
            $lastMinute = $lastSourceEnd->toDateString() !== $date
                ? 1440 : $lastSourceEnd->hour * 60 + $lastSourceEnd->minute;
            $minMinute = min($minMinute, $firstMinute);
            $maxMinute = max($maxMinute, $lastMinute);
        }

        $startIndex = intdiv($minMinute, $step);
        $endIndex = (int) ceil($maxMinute / $step);
        $slots = [];
        $publicEventCache = [];
        $viewer = $viewer?->canonical();
        $viewerIdentityIds = $viewer?->identityIds() ?? [];
        $isSuperadmin = $viewer?->hasSystemRole(UserSystemRoleEnum::SUPERADMIN) ?? false;

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
                    // The native court calendar is authoritative after an import.
                    // It includes later manual edits and the venue minimum price,
                    // matching the booking quote instead of stale source prices.
                    $priceMinor = $nativePrices[$index] ?? (int) collect($parts)->sum('price_minor');
                } else {
                    $priceMinor = null;
                }
            } else {
                $priceMinor = $nativePrices[$index]
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
            $selectable = $rentalEnabled && ! $stale && $inFuture && $start->lessThanOrEqualTo($latestStart)
                && $status === 'available' && $priceMinor !== null;

            // Source tariffs remain untouched; public prices include the
            // effective commission and tariffs for one individual interval.
            // A multi-interval selection gets its own server-side preview:
            // fixed commissions must only be charged once per booking.
            $basePriceMinor = $priceMinor;
            $priceBreakdown = null;
            if ($basePriceMinor !== null) {
                $basePriceMinor = $effective['requires_payment'] ? (int) $basePriceMinor : 0;
                $adjusted = $this->pricingRules->evaluate(
                    $venue, $court, $start, $step, $step,
                    VenueBookingScopeEnum::WHOLE, $basePriceMinor,
                );
                $priceBreakdown = $this->commissions->calculate(
                    $venue, $court, $adjusted['total_minor'], $policy?->currency ?? 'RUB',
                );
                $priceMinor = $priceBreakdown['total_minor'];
            }

            $coveringBookings = $status === 'booked' ? $bookings->filter(
                static fn (VenueBooking $booking): bool => $booking->starts_at->lessThanOrEqualTo($start)
                    && $booking->ends_at->greaterThanOrEqualTo($end),
            ) : collect();
            $booking = $coveringBookings->count() === 1 ? $coveringBookings->first() : null;
            $event = $booking === null ? null
                : ($booking->event ?? $linkedEvents->get($booking->id));
            $isPublicEvent = $event !== null
                && $event->visibility === EventVisibilityEnum::PUBLIC
                && in_array($event->status, [
                    EventStatusEnum::PUBLISHED, EventStatusEnum::COMPLETED,
                    EventStatusEnum::CANCELLED,
                ], true);
            $eventDetails = null;
            if ($event !== null && ! isset($publicEventCache[$event->id])) {
                // Visibility-safe summary for *every* attached event. Private
                // events are visible as occupied activities but never disclose
                // title, description, participants, organizer or a route.
                $details = [
                    'public' => $isPublicEvent,
                    'visibility' => $isPublicEvent
                        ? 'Публичное'
                        : ($event->visibility === EventVisibilityEnum::PRIVATE
                            ? 'По приглашению' : 'Подробности не опубликованы'),
                    'type' => $event->type?->label() ?? 'Мероприятие',
                    'title' => $isPublicEvent ? $event->title : null,
                    'status' => $isPublicEvent ? $event->status?->label() : null,
                    'description' => null,
                    'url' => null,
                    'confirmed_count' => null,
                    'tentative_count' => null,
                    'max_participants' => null,
                    'organizer_name' => null,
                    'organizer_user_id' => null,
                    'participation' => null,
                ];
                if ($isPublicEvent) {
                    $organizer = $event->organizerActor?->user;
                    $organizerName = trim(implode(' ', array_filter([
                        $organizer?->profile?->first_name,
                        $organizer?->profile?->last_name,
                    ]))) ?: ($organizer?->username ?: 'Организатор');
                    $counts = $this->eventParticipation->publicStatusCounts($event);
                    $participant = $viewer === null ? null
                        : $this->eventParticipation->effectiveParticipant($event, $viewer);
                    $isOrganizer = $participant?->role === EventParticipantRoleEnum::ORGANIZER;
                    $wasConfirmed = $participant?->status === EventParticipantStatusEnum::CONFIRMED;
                    $alreadyAttending = $wasConfirmed
                        && $participant->confirmation_version === $event->participation_confirmation_version;
                    $hasPlaces = $event->max_participants === null
                        || $counts['confirmed'] < $event->max_participants;
                    $isOpen = $event->status === EventStatusEnum::PUBLISHED
                        && $event->ends_at->isFuture();
                    // Mirror the existing ShowVenueHandler / Event participation
                    // eligibility. Final validation always occurs in the use case.
                    $canJoin = $isOpen && ! $alreadyAttending && (
                        $isOrganizer ? (! $wasConfirmed && $hasPlaces)
                            : ($hasPlaces || $wasConfirmed)
                    );
                    $canTentative = $isOpen && ! $hasPlaces && ! $alreadyAttending
                        && ! $isOrganizer && ! $canJoin;
                    $joinState = match (true) {
                        ! $isOpen => 'ended',
                        $alreadyAttending => 'joined',
                        $isOrganizer && ! $canJoin => 'organizer',
                        $canJoin && $wasConfirmed => 'reconfirm',
                        $canJoin => 'open',
                        ! $hasPlaces => 'full',
                        default => 'unavailable',
                    };
                    $joinMethod = $isOrganizer && $canJoin ? 'POST' : 'PATCH';
                    $joinUrl = ($canJoin || $canTentative)
                        ? ($joinMethod === 'POST'
                            ? route('events.join', $event->routeIdentifier())
                            : route('events.participation', $event->routeIdentifier()))
                        : null;
                    $details = [
                        ...$details,
                        'description' => Str::limit(trim(strip_tags((string) $event->description)), 500),
                        'url' => route('events.show', $event->routeIdentifier()),
                        'confirmed_count' => $counts['confirmed'],
                        'tentative_count' => $counts['tentative'],
                        'max_participants' => $event->max_participants,
                        'organizer_name' => $organizerName,
                        'organizer_user_id' => $organizer?->id,
                        'participation' => [
                            'state' => $joinState,
                            'can_join' => $canJoin,
                            'can_tentative' => $canTentative,
                            'join_method' => $joinMethod,
                            'join_url' => $joinUrl,
                        ],
                    ];
                }
                $publicEventCache[$event->id] = $details;
            }
            if ($event !== null) {
                $eventDetails = $publicEventCache[$event->id];
            }

            // Never reveal ownership or a booking identifier to another user.
            // The existing event-from-booking handler enforces these rules again.
            $canCreateEvent = $booking !== null && $event === null
                && $viewer !== null
                && $booking->requester_user_id === $viewer->id
                && $booking->flow === 'rental'
                && $booking->status === VenueBookingStatusEnum::CONFIRMED
                && $booking->starts_at->isFuture()
                && $booking->event_id === null
                && config('features.venue_rental.booking_events', false);

            // The schedule is public; a request URL and the customer's login
            // must only be returned to a currently authorized participant.
            $bookingUrl = null;
            $requesterLogin = null;
            $requesterIsViewer = false;
            if ($booking !== null && $booking->status === VenueBookingStatusEnum::CONFIRMED
                && $viewer !== null && $viewerActor !== null) {
                $isRequester = in_array((int) $booking->requester_user_id, $viewerIdentityIds, true);
                $confirmedByUserId = $booking->transitions->last()?->actor?->user_id;
                $isConfirmingRepresentative = $confirmedByUserId !== null
                    && in_array((int) $confirmedByUserId, $viewerIdentityIds, true);
                if ($isRequester || $isSuperadmin || $isConfirmingRepresentative) {
                    try {
                        // Do not advertise a link that the booking controller
                        // would reject after a representative's rights change.
                        $this->bookingAuthorization->assertCanView($viewerActor, $booking, $venue);
                        $bookingUrl = route('account.venue-bookings.show', $booking);
                        $requesterLogin = $booking->requester?->canonical()->username;
                        $requesterIsViewer = $isRequester;
                    } catch (VenueBookingTransitionException) {
                        // Booking details stay private, even in a public timetable.
                    }
                }
            }

            $slots[] = [
                'booking_group' => $booking === null ? null : [
                    'key' => substr(hash_hmac('sha256', $date.'|'.$booking->id, (string) config('app.key')), 0, 24),
                    'has_event' => $event !== null,
                    'event' => $eventDetails,
                    'create_event_url' => $canCreateEvent
                        ? route('account.venue-bookings.event.create', $booking)
                        : null,
                    'request_url' => $bookingUrl,
                    'requester_login' => $requesterLogin,
                    'requester_is_viewer' => $requesterIsViewer,
                ],
                'starts_at' => $startTime,
                'ends_at' => $endTime,
                'status' => $status,
                'selectable' => $selectable,
                'base_price_minor' => $basePriceMinor,
                'price_minor' => $priceMinor,
                'pricing_breakdown' => $priceBreakdown === null ? null : [
                    'portal_fee_minor' => $priceBreakdown['portal_fee_minor'],
                    'agent_fee_minor' => $priceBreakdown['agent_fee_minor'],
                ],
            ];
        }

        $preview = null;
        if ($previewStart !== null || $previewDurationMinutes !== null) {
            if ($previewStart === null || $previewDurationMinutes === null
                || ! preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/D', $previewStart)
                || $previewDurationMinutes < $step
                || $previewDurationMinutes > $maximum
                || $previewDurationMinutes % $step !== 0) {
                throw new InvalidArgumentException('Некорректный интервал для расчёта стоимости.');
            }
            $first = collect($slots)->search(
                static fn (array $slot): bool => $slot['starts_at'] === $previewStart,
            );
            $count = intdiv($previewDurationMinutes, $step);
            $selected = $first === false ? [] : array_slice($slots, $first, $count);
            if (count($selected) !== $count || collect($selected)->contains(
                static fn (array $slot): bool =>
                    ! $slot['selectable'] || $slot['status'] !== 'available'
                    || $slot['base_price_minor'] === null,
            )) {
                throw new InvalidArgumentException('Выбранные интервалы недоступны для расчёта.');
            }

            $baseAmount = array_sum(array_column($selected, 'base_price_minor'));
            $priceAfterRules = $this->pricingRules->evaluate(
                $venue, $court, $day->setTimeFromTimeString($previewStart),
                $previewDurationMinutes, $step, VenueBookingScopeEnum::WHOLE, $baseAmount,
            );
            $breakdown = $this->commissions->calculate(
                $venue, $court, $priceAfterRules['total_minor'], $policy?->currency ?? 'RUB',
            );
            $preview = [
                'base_amount_minor' => $baseAmount,
                'after_rules_minor' => $priceAfterRules['total_minor'],
                'provider_amount_minor' => $breakdown['provider_amount_minor'],
                'portal_fee_minor' => $breakdown['portal_fee_minor'],
                'agent_fee_minor' => $breakdown['agent_fee_minor'],
                'total_minor' => $breakdown['total_minor'],
            ];
        }

        return [
            'viewer_authenticated' => $viewer !== null,
            'price_preview' => $preview,
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
