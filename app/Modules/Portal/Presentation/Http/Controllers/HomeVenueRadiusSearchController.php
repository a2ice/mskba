<?php

namespace App\Modules\Portal\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Venue\Application\UseCases\SearchVenuesHandler;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class HomeVenueRadiusSearchController extends Controller
{
    public function __invoke(
        Request $request,
        string $latitude,
        string $longitude,
        string $radiusKm,
        SearchVenuesHandler $searchVenues,
        CurrentActorResolver $actors,
    ): JsonResponse {
        $anchor = validator([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius_km' => $radiusKm,
        ], [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['required', 'numeric', 'min:0.1', 'max:50'],
        ])->validate();

        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
            'venue_id' => ['nullable', 'integer', 'min:1'],
            'city' => ['nullable', 'string', 'max:100'],
            'street' => ['nullable', 'string', 'max:150'],
            'type' => ['nullable', Rule::enum(VenueTypeEnum::class)],
            'status' => ['nullable', Rule::enum(VenueStatusEnum::class)],
            'metro_station_id' => ['nullable', 'integer', 'exists:metro_stations,id'],
            'confirmed_only' => ['nullable', 'boolean'],
            'operational_status' => ['nullable', Rule::enum(VenueOperationalStatusEnum::class)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $venues = $searchVenues->handle(
            user: $request->user(),
            actor: $actors->resolveForRequest($request),
            query: $validated['query'] ?? null,
            venueId: isset($validated['venue_id']) ? (int) $validated['venue_id'] : null,
            city: $validated['city'] ?? null,
            street: $validated['street'] ?? null,
            latitude: (float) $anchor['latitude'],
            longitude: (float) $anchor['longitude'],
            radiusKm: (float) $anchor['radius_km'],
            type: isset($validated['type']) ? VenueTypeEnum::from($validated['type']) : null,
            status: isset($validated['status']) ? VenueStatusEnum::from($validated['status']) : null,
            metroStationId: isset($validated['metro_station_id']) ? (int) $validated['metro_station_id'] : null,
            confirmedOnly: $request->boolean('confirmed_only'),
            operationalStatus: isset($validated['operational_status'])
                ? VenueOperationalStatusEnum::from($validated['operational_status'])
                : null,
            limit: isset($validated['limit']) ? (int) $validated['limit'] : 20,
        );

        $hoopsByVenue = Venue::query()
            ->with('characteristics')
            ->whereKey(collect($venues)->pluck('id'))
            ->get()
            ->mapWithKeys(fn (Venue $venue): array => [
                $venue->id => (int) ($venue->characteristics?->hoops_count ?? 1),
            ]);

        return response()->json([
            'venues' => collect($venues)->map(fn ($venue): array => [
                'id' => $venue->id,
                'name' => $venue->name,
                'type' => $venue->type,
                'status' => $venue->status,
                'is_confirmed' => $venue->status === VenueStatusEnum::CONFIRMED->label(),
                'description' => $venue->shortDescription,
                'address' => $venue->displayAddress,
                'raw_address' => $venue->rawAddress,
                'requires_payment' => $venue->requiresPayment,
                'requires_booking_approval' => $venue->requiresBookingApproval,
                'has_free_access' => $venue->hasFreeAccess(),
                'operational_status' => $venue->operationalStatus,
                'metro_stations' => $venue->metroStations,
                'tags' => $venue->tags,
                'latitude' => $venue->latitude,
                'longitude' => $venue->longitude,
                'url' => route('venues.show', $venue->routeIdentifier()),
                'preview_url' => route('venues.preview', $venue->routeIdentifier()),
                'hoops_count' => $hoopsByVenue->get($venue->id, 1),
            ])->all(),
        ]);
    }
}
