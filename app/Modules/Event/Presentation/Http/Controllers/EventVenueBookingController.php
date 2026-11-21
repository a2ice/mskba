<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\EventVenueInterestService;
use App\Modules\Event\Application\Services\EventVenueInterestChat;
use App\Modules\Event\Application\Services\EventVenueBookingProposals;
use App\Modules\Event\Domain\Models\EventVenueInterest;
use App\Modules\Event\Domain\Models\EventVenueBookingProposal;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversation;
use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Application\UseCases\UpdateEventHandler;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\VerifiedContactOperationalPermissionGranter;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\Venue\Application\Services\EffectiveVenueHours;
use App\Modules\VenueIntegration\Application\Services\FindSportOnDemandRefresh;
use App\Modules\VenueIntegration\Application\Services\AppliedImportedCourtAvailability;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RequestVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingQuote;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Presentation\Theming\ThemeResolver;
use App\Support\Features\FeatureDisabledException;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class EventVenueBookingController extends Controller
{
    private const CONTACT_REQUIRED = 'Для бронирования необходимо иметь подтверждённый контакт';

    public function quote(
        Request $request,
        string $event,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        QuoteVenueBookingHandler $quotes,
        EventVenueBookingProposals $proposals,
    ): Response|RedirectResponse|JsonResponse {
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'interest_id' => ['nullable', 'integer', 'min:1'],
            'proposal_id' => ['nullable', 'integer', 'min:1'],
            'rental_action' => ['nullable', Rule::in(['check', 'select'])],
            'venue_court_id' => ['required_if:rental_action,check,select', 'nullable', 'integer', 'min:1'],
            'starts_at' => ['required_if:rental_action,select', 'nullable', 'date_format:Y-m-d\\TH:i'],
            'duration_minutes' => ['required_if:rental_action,select', 'nullable', 'integer', 'between:5,1440'],
            'scope' => ['required_if:rental_action,select', 'nullable', Rule::enum(VenueBookingScopeEnum::class)],
        ]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $item = Event::query()->whereRouteIdentifier($event)->with(['venue', 'court'])->firstOrFail();

        try {
            $action = $validated['rental_action'] ?? null;
            $this->assertCanRequest($item, $actor, $access, (int) $validated['expected_version'], $action === 'select');
            if ($action !== null && (isset($validated['interest_id']) || isset($validated['proposal_id']))) {
                throw new InvalidArgumentException('Выбор нового времени доступен только из самостоятельной аренды.');
            }
            if ($action === 'check') {
                return $this->checkPlannedTime($item, (int) $validated['venue_court_id']);
            }
            $interest = isset($validated['interest_id'])
                ? $item->venueInterests()->findOrFail($validated['interest_id']) : null;
            if ($interest !== null && ($interest->status !== 'open'
                || $interest->revision !== $item->participation_confirmation_version)) {
                throw new InvalidArgumentException('Предзаявка устарела. Выберите актуальную версию мероприятия.');
            }
            if (isset($validated['proposal_id'])) {
                if ($interest === null) {
                    throw new InvalidArgumentException('Для предложения необходимо указать предзаявку.');
                }
                $proposal = EventVenueBookingProposal::query()
                    ->where('event_venue_interest_id', $interest->id)
                    ->findOrFail($validated['proposal_id']);
                $proposals->assertPendingForOrganizer($proposal, $actor);
            }

            $venue = $item->venue;
            $selection = $action === 'select' ? $this->rentalSelection($item, $validated) : null;
            $scope = $selection['scope'] ?? $item->planned_booking_scope ?? VenueBookingScopeEnum::WHOLE;
            $startsAt = $selection['starts'] ?? CarbonImmutable::instance($item->starts_at);
            $duration = $selection['duration'] ?? (int) $item->starts_at->diffInMinutes($item->ends_at);
            $court = $selection['court'] ?? $item->court;
            if ($selection !== null) {
                $this->assertFreshSelection($venue, $court, $startsAt, $startsAt->addMinutes($duration), $scope);
            }
            $quote = $selection !== null && $this->isFreeWithoutPolicy($venue)
                ? null : $quotes->handle($venue, $startsAt, $duration, $scope, $actor->user, $court);

            $selectedRental = $selection === null ? null : [
                'venue_court_id' => $court->id,
                'court_name' => $court->publicName(),
                'starts_at' => $startsAt->setTimezone($venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow'))->format('Y-m-d\\TH:i'),
                'duration_minutes' => $duration,
                'scope' => $scope->value,
                'date_label' => $startsAt->setTimezone($venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow'))->format('d.m.Y H:i')
                    .'–'.$startsAt->addMinutes($duration)->setTimezone($venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow'))->format('H:i'),
                'changes_event' => $this->selectionChangesEvent($item, $selection),
            ];

            return ThemeResolver::page('events.booking-quote', [
                'event' => $item,
                'quote' => $quote,
                'expectedVersion' => $item->participation_confirmation_version,
                'interestId' => $interest?->id,
                'proposalId' => $proposal->id ?? null,
                'selectedRental' => $selectedRental,
            ]);
        } catch (VenueBookingPolicyException|VenueBookingTransitionException|FeatureDisabledException|InvalidArgumentException|DomainException $exception) {
            if ($request->expectsJson() && $exception->getMessage() === self::CONTACT_REQUIRED) {
                return response()->json(['code' => 'CONTACT_REQUIRED', 'message' => self::CONTACT_REQUIRED], 422);
            }
            if ($request->expectsJson() && $request->input('rental_action') === 'check') {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
            if ($request->input('rental_action') === 'select') {
                return redirect()->route('events.show', $item->routeIdentifier())
                    ->with('event_rental_error', $exception->getMessage())
                    ->with('error', $exception->getMessage());
            }
            return redirect()->route('events.show', $item->routeIdentifier())
                ->with('error', $exception instanceof FeatureDisabledException
                    ? 'Онлайн-бронирование временно недоступно.'
                    : ($exception->getMessage() === self::CONTACT_REQUIRED
                        ? self::CONTACT_REQUIRED : $exception->getMessage().' Измените время или площадку мероприятия и повторите расчёт.'));
        }
    }

    public function store(
        Request $request,
        string $event,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        EventVenueInterestService $interests,
        VenueEventAvailability $availability,
        RequestVenueBookingHandler $bookings,
        EventVenueBookingProposals $proposals,
        EventVenueInterestChat $chat,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'quote_id' => ['nullable', 'uuid'],
            'interest_id' => ['nullable', 'integer', 'min:1'],
            'proposal_id' => ['nullable', 'integer', 'min:1'],
            'rental_action' => ['nullable', Rule::in(['select'])],
            'venue_court_id' => ['required_if:rental_action,select', 'nullable', 'integer', 'min:1'],
            'starts_at' => ['required_if:rental_action,select', 'nullable', 'date_format:Y-m-d\\TH:i'],
            'duration_minutes' => ['required_if:rental_action,select', 'nullable', 'integer', 'between:5,1440'],
            'scope' => ['required_if:rental_action,select', 'nullable', Rule::enum(VenueBookingScopeEnum::class)],
        ]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $reference = Event::query()->whereRouteIdentifier($event)->firstOrFail();

        try {
            $result = DB::transaction(function () use ($reference, $actor, $access, $validated, $interests, $availability, $bookings, $proposals, $chat): array {
                // Lock order is venue -> event -> quote/booking.
                $venue = Venue::query()->whereKey($reference->venue_id)->lockForUpdate()->firstOrFail();
                $event = Event::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();
                if ($event->venue_id !== $venue->id) {
                    throw new InvalidArgumentException('Место мероприятия изменилось. Повторите расчёт.');
                }
                $explicitSelection = ($validated['rental_action'] ?? null) === 'select';
                $this->assertCanRequest($event, $actor, $access, (int) $validated['expected_version'], $explicitSelection);
                if ($explicitSelection) {
                    if (isset($validated['interest_id']) || isset($validated['proposal_id'])) {
                        throw new InvalidArgumentException('Нельзя менять время предложения площадки.');
                    }
                    $selection = $this->rentalSelection($event, $validated);
                    $start = $selection['starts'];
                    $end = $start->addMinutes($selection['duration']);
                    $quoteId = $validated['quote_id'] ?? null;
                    if ($quoteId !== null) {
                        $selectedQuote = VenueBookingQuote::query()->where('public_id', $quoteId)->firstOrFail();
                        if ($selectedQuote->venue_id !== $venue->id
                            || $selectedQuote->venue_court_id !== $selection['court']->id
                            || $selectedQuote->scope !== $selection['scope']
                            || ! $selectedQuote->starts_at->equalTo($start)
                            || ! $selectedQuote->ends_at->equalTo($end)
                            || $selectedQuote->quoted_for_user_id !== $actor->user?->canonical()->id) {
                            throw new InvalidArgumentException('Расчёт не соответствует выбранному времени.');
                        }
                    } elseif (! $this->isFreeWithoutPolicy($venue)) {
                        throw new InvalidArgumentException('Сначала получите актуальный расчёт аренды.');
                    }
                    // The final submission is the only point where the event can change.
                    // All changes and the booking share the same transaction.
                    $databaseTimezone = DB::connection()->getConfig('timezone') ?: config('app.timezone', 'UTC');
                    $availability->assertAvailable($venue, $start->setTimezone($databaseTimezone), $end->setTimezone($databaseTimezone), scope: $selection['scope'], court: $selection['court']);
                    if (app(EffectiveVenueHours::class)->intervals($venue, $selection['court'], $start) === null) {
                        throw new InvalidArgumentException('Для выбранного времени нет подтверждённого расписания.');
                    }
                    if ($this->selectionChangesEvent($event, $selection)) {
                        if ($event->starts_at === null) {
                            $timezone = DB::connection()->getConfig('timezone') ?: config('app.timezone', 'UTC');
                            $event->forceFill([
                                'venue_court_id' => $selection['court']->id,
                                'planned_booking_scope' => $selection['scope'],
                                'starts_at' => $start->setTimezone($timezone),
                                'ends_at' => $end->setTimezone($timezone),
                                'proposed_time_options' => [['starts_at' => $validated['starts_at'], 'duration_minutes' => $selection['duration']]],
                                'status' => EventStatusEnum::PUBLISHED,
                            ])->save();
                            $interests->createFor($event, $actor);
                        } else {
                            $event = app(UpdateEventHandler::class)->handle($event->routeIdentifier(), $actor, [
                                'title' => $event->title, 'type' => $event->type->value,
                                'visibility' => $event->visibility->value, 'description' => $event->description,
                                'max_participants' => $event->max_participants,
                                'venue_id' => $event->venue_id, 'venue_court_id' => $selection['court']->id,
                                'booking_scope' => $selection['scope']->value,
                                'starts_at' => $start->toIso8601String(), 'duration_minutes' => $selection['duration'],
                            ]);
                        }
                    }
                }

                $interest = isset($validated['interest_id'])
                    ? $event->venueInterests()->lockForUpdate()->findOrFail($validated['interest_id'])
                    : $event->venueInterests()->where('status', 'open')->latest('revision')->lockForUpdate()->first();
                if ($interest !== null && ($interest->status !== 'open'
                    || $interest->revision !== $event->participation_confirmation_version
                    || $interest->venue_id !== $event->venue_id
                    || ! $interest->starts_at->equalTo($event->starts_at)
                    || ! $interest->ends_at->equalTo($event->ends_at))) {
                    throw new InvalidArgumentException('Предзаявка больше не соответствует мероприятию.');
                }
                $proposal = null;
                if (isset($validated['proposal_id'])) {
                    if ($interest === null) {
                        throw new InvalidArgumentException('Не указана исходная предзаявка.');
                    }
                    $proposal = EventVenueBookingProposal::query()
                        ->where('event_venue_interest_id', $interest->id)
                        ->lockForUpdate()->findOrFail($validated['proposal_id']);
                    $proposals->assertPendingForOrganizer($proposal, $actor);
                }

                $quoteId = $validated['quote_id'] ?? null;
                if ($quoteId === null && $venue->hasFreeAccess()) {
                    $court = $event->court;
                    $scope = $event->planned_booking_scope ?? VenueBookingScopeEnum::WHOLE;
                    $availability->assertAvailable($venue, $event->starts_at, $event->ends_at, scope: $scope, court: $court);
                    $event->booking()->create([
                        'venue_id' => $venue->id,
                        'venue_court_id' => $court?->id,
                        'created_by_actor_id' => $actor->id,
                        'status' => VenueBookingStatusEnum::CONFIRMED,
                        'scope' => $scope,
                        'starts_at' => $event->starts_at,
                        'ends_at' => $event->ends_at,
                    ]);
                    $event->forceFill(['booking_deferred' => false])->save();
                    $interests->markBookingRequested($event);
                    DB::afterCommit(static fn () => event(new EventChanged($event->id)));

                    return ['event' => $event, 'booking' => null];
                }

                if ($quoteId === null) {
                    throw new InvalidArgumentException('Сначала получите актуальный расчёт аренды.');
                }
                $quote = VenueBookingQuote::query()->where('public_id', $quoteId)->firstOrFail();
                $scope = $event->planned_booking_scope ?? VenueBookingScopeEnum::WHOLE;
                if ($quote->venue_id !== $event->venue_id
                    || ($event->venue_court_id !== null && $quote->venue_court_id !== $event->venue_court_id)
                    || $quote->scope !== $scope
                    || ! $quote->starts_at->equalTo($event->starts_at)
                    || ! $quote->ends_at->equalTo($event->ends_at)
                    || $quote->quoted_for_user_id !== $actor->user?->canonical()->id) {
                    throw new InvalidArgumentException('Расчёт больше не соответствует мероприятию. Обновите его.');
                }

                $booking = $bookings->handle($actor, $quoteId, (string) Str::uuid(), (string) Str::uuid());
                $booking->forceFill([
                    'event_id' => $event->id,
                    'event_venue_interest_id' => $interest?->id,
                    'interest_booking_initiator' => $proposal !== null ? 'venue' : 'organizer',
                ])->save();
                if ($interest !== null) {
                    $conversation = VenueBookingConversation::query()
                        ->where('event_venue_interest_id', $interest->id)
                        ->lockForUpdate()->first();
                    if ($conversation !== null) {
                        $conversation->forceFill(['venue_booking_id' => $booking->id])->save();
                    }
                    $chat->system($interest, $actor, $proposal !== null
                        ? 'Организатор принял предложение площадки и отправил заявку на аренду. Инициатор предложения — представитель площадки.'
                        : 'Организатор самостоятельно отправил заявку на аренду по предзаявке.');
                }
                if ($proposal !== null) {
                    $proposal->forceFill([
                        'status' => 'accepted', 'booking_id' => $booking->id, 'responded_at' => now(),
                    ])->save();
                }
                $event->forceFill([
                    'booking_id' => $booking->id,
                    'booking_snapshot' => [
                        'booking_public_id' => $booking->public_id,
                        'scope' => $booking->scope?->value,
                        'starts_at' => $booking->starts_at->toIso8601String(),
                        'ends_at' => $booking->ends_at->toIso8601String(),
                        'pricing' => data_get($booking->quote_snapshot, 'pricing'),
                        'policy_version' => data_get($booking->quote_snapshot, 'policy.version'),
                    ],
                ])->save();
                $interests->markBookingRequested($event);
                if ($interest !== null) {
                    DB::afterCommit(fn () => $chat->notify($interest, $actor,
                        'Заявка на аренду создана', 'По мероприятию «'.$event->title.'» создана заявка на бронь. Статус и действия доступны в переписке.'));
                }
                DB::afterCommit(static fn () => event(new EventChanged($event->id)));

                return ['event' => $event, 'booking' => $booking];
            }, 3);
        } catch (VenueBookingPolicyException|VenueBookingTransitionException|FeatureDisabledException|InvalidArgumentException|DomainException $exception) {
            if ($request->expectsJson() && $exception->getMessage() === self::CONTACT_REQUIRED) {
                return response()->json(['code' => 'CONTACT_REQUIRED', 'message' => self::CONTACT_REQUIRED], 422);
            }
            if ($request->input('rental_action') === 'select') {
                return redirect()->route('events.show', $reference->routeIdentifier())
                    ->with('event_rental_error', $exception->getMessage())
                    ->with('error', $exception->getMessage());
            }
            return redirect()->route('events.show', $reference->routeIdentifier())
                ->with('error', $exception instanceof FeatureDisabledException
                    ? 'Онлайн-бронирование временно недоступно.'
                    : ($exception->getMessage() === self::CONTACT_REQUIRED
                        ? self::CONTACT_REQUIRED : $exception->getMessage().' При необходимости согласуйте другое время или площадку.'));
        }

        return $result['booking'] === null
            ? redirect()->route('events.show', $result['event']->routeIdentifier())
                ->with('status', 'Свободная площадка забронирована для мероприятия.')
            : redirect()->route('account.venue-bookings.show', $result['booking'])
                ->with('status', 'Заявка на аренду отправлена и привязана к мероприятию. До подтверждения зал не забронирован.');
    }


    private function isFreeWithoutPolicy(Venue $venue): bool
    {
        return $venue->hasFreeAccess() && ! VenueBookingPolicy::query()->where('venue_id', $venue->id)
            ->where('active_marker', true)->where('is_enabled', true)->exists();
    }

    private function rentalSelection(Event $event, array $input): array
    {
        $court = $event->venue->courts()->findOrFail((int) $input['venue_court_id']);
        $timezone = $event->venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');

        return [
            'court' => $court,
            'starts' => CarbonImmutable::parse($input['starts_at'], $timezone),
            'duration' => (int) $input['duration_minutes'],
            'scope' => VenueBookingScopeEnum::from($input['scope']),
        ];
    }

    private function selectionChangesEvent(Event $event, array $selection): bool
    {
        return $event->starts_at === null || $event->ends_at === null
            || $event->venue_court_id !== $selection['court']->id
            || ! $event->starts_at->equalTo($selection['starts'])
            || ! $event->ends_at->equalTo($selection['starts']->addMinutes($selection['duration']))
            || ($event->planned_booking_scope ?? VenueBookingScopeEnum::WHOLE) !== $selection['scope'];
    }

    private function assertFreshSelection(Venue $venue, VenueCourt $court, CarbonImmutable $start, CarbonImmutable $end, VenueBookingScopeEnum $scope): void
    {
        $refresh = app(FindSportOnDemandRefresh::class)->forCourt($court);
        if (in_array($refresh['state'], ['failed', 'updating'], true)) {
            throw new InvalidArgumentException($refresh['message'] ?? 'Не удалось проверить актуальное расписание.');
        }
        $databaseTimezone = DB::connection()->getConfig('timezone') ?: config('app.timezone', 'UTC');
        $timezone = $venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
        app(VenueEventAvailability::class)->assertAvailable($venue, $start->setTimezone($databaseTimezone), $end->setTimezone($databaseTimezone), scope: $scope, court: $court);
        if (app(EffectiveVenueHours::class)->intervals($venue, $court, $start->setTimezone($timezone)) === null) {
            throw new InvalidArgumentException('Для выбранного времени нет подтверждённого расписания.');
        }
    }

    private function checkPlannedTime(Event $event, int $courtId): JsonResponse
    {
        if ($event->starts_at === null || $event->ends_at === null) {
            return response()->json(['status' => 'unset']);
        }
        $court = $event->venue->courts()->findOrFail($courtId);
        try {
            $this->assertFreshSelection($event->venue, $court, $event->starts_at, $event->ends_at,
                $event->planned_booking_scope ?? VenueBookingScopeEnum::WHOLE);
            return response()->json(['status' => 'available']);
        } catch (InvalidArgumentException $exception) {
            $message = $exception->getMessage();
            $occupied = str_contains($message, 'уже занято');
            if (str_contains($message, 'занято или недоступно по расписанию FindSport')) {
                $imports = app(AppliedImportedCourtAvailability::class);
                $import = $imports->activeFor($court);
                $occupied = $import !== null && $imports->blockingSlots($import, $event->starts_at, $event->ends_at)
                    ->contains(fn ($slot) => $slot->status === 'booked' && $slot->day_complete);
            }
            $status = $occupied ? 'occupied' : 'unavailable';
            return response()->json(['status' => $status, 'message' => $message]);
        }
    }

    private function assertCanRequest(
        Event $event,
        Actor $actor,
        EventManagementAccess $access,
        int $expectedVersion,
        bool $allowUndated = false,
    ): void {
        $access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::UPDATE_EVENT);
        if (! $event->booking_deferred || $event->booking_id !== null || $event->booking()->exists()) {
            throw new InvalidArgumentException('Для этого мероприятия уже создана бронь или заявка.');
        }
        $undated = $allowUndated && $event->status === EventStatusEnum::DRAFT
            && $event->starts_at === null && $event->ends_at === null
            && $event->organizerActor?->user?->canonical()?->id === $actor->user?->canonical()?->id;
        if (! $undated && ($event->status !== EventStatusEnum::PUBLISHED || $event->ends_at === null || $event->ends_at->lessThanOrEqualTo(now()))) {
            throw new InvalidArgumentException('Нельзя забронировать завершённое или не опубликованное мероприятие.');
        }
        if ($event->participation_confirmation_version !== $expectedVersion) {
            throw new InvalidArgumentException('Площадка или время мероприятия изменились. Обновите страницу.');
        }
        $user = $actor->user?->canonical();
        abort_if($user === null || $user->isBlocked() || $user->trashed(), 403);
        if (! app(VerifiedContactOperationalPermissionGranter::class)->hasVerifiedContact($user)) {
            throw new InvalidArgumentException(self::CONTACT_REQUIRED);
        }
    }
}
