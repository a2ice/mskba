<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\UseCases\CreateEventFromConfirmedVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RescheduleBookedEventHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class VenueBookingEventController extends Controller
{
    public function store(Request $request, VenueBooking $venueBooking, CurrentActorResolver $actors, CreateEventFromConfirmedVenueBookingHandler $handler): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(EventTypeEnum::class)],
            'visibility' => ['required', Rule::enum(EventVisibilityEnum::class)],
            'description' => ['nullable', 'string', 'max:10000'],
            'max_participants' => ['nullable', 'integer', 'min:2', 'max:1000'],
            'emergency_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        try {
            $event = $handler->handle($venueBooking->id, $actors->resolveForRequest($request), $data);
        } catch (VenueBookingTransitionException $exception) {
            $status = $exception->errorCode === 'BOOKING_FORBIDDEN' ? 403 : 409;

            return $request->expectsJson()
                ? response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], $status)
                : back()->withInput()->with('error', $exception->getMessage());
        }

        return $request->expectsJson()
            ? response()->json(['event_id' => $event->id, 'event_url' => route('events.show', $event->routeIdentifier()), 'booking_id' => $venueBooking->public_id], 201)
            : redirect()->route('events.show', $event->routeIdentifier())->with('status', 'Мероприятие создано из подтверждённой брони.');
    }

    public function reschedule(Request $request, VenueBooking $venueBooking, Event $event, CurrentActorResolver $actors, RescheduleBookedEventHandler $handler): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'scope' => ['required', Rule::enum(VenueBookingScopeEnum::class)],
            'emergency_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $venue = Venue::query()->with('schedule')->findOrFail($data['venue_id']);
        $timezone = $venue->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
        try {
            $updated = $handler->handle(
                $venueBooking->id, $event->id, $actors->resolveForRequest($request), (int) $data['venue_id'],
                CarbonImmutable::parse($data['starts_at'], $timezone), (int) $data['duration_minutes'],
                VenueBookingScopeEnum::from($data['scope']), $data['emergency_reason'] ?? null,
            );
        } catch (VenueBookingTransitionException|InvalidArgumentException $exception) {
            $code = $exception instanceof VenueBookingTransitionException ? $exception->errorCode : 'EVENT_FORBIDDEN';
            $status = $code === 'BOOKING_FORBIDDEN' ? 403 : 409;

            return $request->expectsJson()
                ? response()->json(['code' => $code, 'message' => $exception->getMessage()], $status)
                : back()->withInput()->with('error', $exception->getMessage());
        }

        return $request->expectsJson()
            ? response()->json(['event_id' => $updated->id, 'starts_at' => $updated->starts_at->utc()->toIso8601String(), 'ends_at' => $updated->ends_at->utc()->toIso8601String()])
            : back()->with('status', 'Бронь и мероприятие перенесены.');
    }
}
