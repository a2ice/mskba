<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Queries\GetBookingTimeline;
use App\Modules\VenueBooking\Application\Queries\GetVenueAvailability;
use App\Modules\VenueBooking\Application\Queries\ListOwnerBookingInbox;
use App\Modules\VenueBooking\Application\Queries\ListRequesterBookings;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class VenueBookingProjectionController extends Controller
{
    public function availability(Request $request, Venue $venue, GetVenueAvailability $query): JsonResponse
    {
        $validated = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);
        try {
            return response()->json($query->handle($venue, CarbonImmutable::parse($validated['from']), CarbonImmutable::parse($validated['to'])));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['code' => 'INVALID_AVAILABILITY_RANGE', 'message' => $exception->getMessage()], 422);
        }
    }

    public function requester(Request $request, CurrentActorResolver $actors, ListRequesterBookings $query): JsonResponse|Response
    {
        $projection = $query->handle($actors->resolveForRequest($request), (int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $request->expectsJson() ? response()->json($projection) : ThemeResolver::page('venue-bookings.index', ['projection' => $projection, 'ownerInbox' => false]);
    }

    public function owner(Request $request, CurrentActorResolver $actors, ListOwnerBookingInbox $query): JsonResponse|Response
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'array', 'max:7'],
            'status.*' => [Rule::enum(VenueBookingStatusEnum::class)],
            'venue_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $projection = $query->handle(
            $actors->resolveForRequest($request), $validated['status'] ?? [],
            isset($validated['venue_id']) ? (int) $validated['venue_id'] : null,
            (int) ($validated['page'] ?? 1), (int) ($validated['per_page'] ?? 20),
        );

        return $request->expectsJson() ? response()->json($projection) : ThemeResolver::page('venue-bookings.index', ['projection' => $projection, 'ownerInbox' => true]);
    }

    public function timeline(Request $request, VenueBooking $venueBooking, CurrentActorResolver $actors, GetBookingTimeline $query): JsonResponse
    {
        try {
            return response()->json($query->handle($venueBooking, $actors->resolveForRequest($request), (int) $request->integer('page', 1), (int) $request->integer('per_page', 30)));
        } catch (VenueBookingTransitionException $exception) {
            return response()->json(['code' => $exception->errorCode, 'message' => $exception->getMessage()], 403);
        }
    }
}
