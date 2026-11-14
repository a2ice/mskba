<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\EventVenueInterestService;
use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RequestVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingQuote;
use App\Presentation\Theming\ThemeResolver;
use App\Support\Features\FeatureDisabledException;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class EventVenueBookingController extends Controller
{
    public function quote(
        Request $request,
        string $event,
        CurrentActorResolver $actors,
        EventManagementAccess $access,
        QuoteVenueBookingHandler $quotes,
    ): Response|RedirectResponse {
        $validated = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $item = Event::query()->whereRouteIdentifier($event)->with(['venue', 'court'])->firstOrFail();

        try {
            $this->assertCanRequest($item, $actor, $access, (int) $validated['expected_version']);

            $venue = $item->venue;
            $scope = $item->planned_booking_scope ?? VenueBookingScopeEnum::WHOLE;
            $quote = $quotes->handle(
                $venue,
                CarbonImmutable::instance($item->starts_at),
                (int) $item->starts_at->diffInMinutes($item->ends_at),
                $scope,
                $actor->user,
                $item->court,
            );

            return ThemeResolver::page('events.booking-quote', [
                'event' => $item,
                'quote' => $quote,
                'expectedVersion' => $item->participation_confirmation_version,
            ]);
        } catch (VenueBookingPolicyException|VenueBookingTransitionException|FeatureDisabledException|InvalidArgumentException|DomainException $exception) {
            return redirect()->route('events.show', $item->routeIdentifier())
                ->with('error', $exception instanceof FeatureDisabledException
                    ? 'Онлайн-бронирование временно недоступно.'
                    : $exception->getMessage().' Измените время или площадку мероприятия и повторите расчёт.');
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
    ): RedirectResponse {
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'quote_id' => ['nullable', 'uuid'],
        ]);
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null, 403);
        $reference = Event::query()->whereRouteIdentifier($event)->firstOrFail();

        try {
            $result = DB::transaction(function () use ($reference, $actor, $access, $validated, $interests, $availability, $bookings): array {
                // Lock order is venue -> event -> quote/booking.
                $venue = Venue::query()->whereKey($reference->venue_id)->lockForUpdate()->firstOrFail();
                $event = Event::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();
                if ($event->venue_id !== $venue->id) {
                    throw new InvalidArgumentException('Место мероприятия изменилось. Повторите расчёт.');
                }
                $this->assertCanRequest($event, $actor, $access, (int) $validated['expected_version']);

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
                $booking->forceFill(['event_id' => $event->id])->save();
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
                DB::afterCommit(static fn () => event(new EventChanged($event->id)));

                return ['event' => $event, 'booking' => $booking];
            }, 3);
        } catch (VenueBookingPolicyException|VenueBookingTransitionException|FeatureDisabledException|InvalidArgumentException|DomainException $exception) {
            return redirect()->route('events.show', $reference->routeIdentifier())
                ->with('error', $exception instanceof FeatureDisabledException
                    ? 'Онлайн-бронирование временно недоступно.'
                    : $exception->getMessage().' При необходимости согласуйте другое время или площадку.');
        }

        return $result['booking'] === null
            ? redirect()->route('events.show', $result['event']->routeIdentifier())
                ->with('status', 'Свободная площадка забронирована для мероприятия.')
            : redirect()->route('account.venue-bookings.show', $result['booking'])
                ->with('status', 'Заявка на аренду отправлена и привязана к мероприятию. До подтверждения зал не забронирован.');
    }

    private function assertCanRequest(
        Event $event,
        Actor $actor,
        EventManagementAccess $access,
        int $expectedVersion,
    ): void {
        $access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::UPDATE_EVENT);
        if (! $event->booking_deferred || $event->booking_id !== null || $event->booking()->exists()) {
            throw new InvalidArgumentException('Для этого мероприятия уже создана бронь или заявка.');
        }
        if ($event->status !== EventStatusEnum::PUBLISHED || $event->ends_at->lessThanOrEqualTo(now())) {
            throw new InvalidArgumentException('Нельзя забронировать завершённое или не опубликованное мероприятие.');
        }
        if ($event->participation_confirmation_version !== $expectedVersion) {
            throw new InvalidArgumentException('Площадка или время мероприятия изменились. Обновите страницу.');
        }
        if ($actor->user === null || ! $actor->user->canonical()->isConfirmed()) {
            throw new InvalidArgumentException('Для бронирования нужен подтверждённый пользователь.');
        }
    }
}
