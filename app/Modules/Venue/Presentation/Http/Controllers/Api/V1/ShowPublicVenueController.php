<?php

namespace App\Modules\Venue\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Location\Application\Services\AddressDisplayFormatter;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Venue\Application\UseCases\ListPublicVenuesHandler;
use App\Modules\Venue\Presentation\Http\Resources\PublicVenueResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Show a single confirmed, non-deleted venue. The same visibility policy and
 * public serialization as GET /api/v1/venues and GraphQL venue(id) are reused.
 */
final class ShowPublicVenueController extends Controller
{
    public function __invoke(
        Request $request,
        ListPublicVenuesHandler $publicVenues,
        AddressDisplayFormatter $addressFormatter,
        string $venue,
    ): JsonResponse {
        $record = $publicVenues->find((int) $venue);

        if ($record === null) {
            return response()->json(['message' => 'Площадка не найдена.'], 404);
        }

        // The shared venue map is used both by the detail page and PageBuilder cards.
        // Load metro only for this detail request, not for every catalog item.
        $record->loadMissing('location.metroStations.line');
        $metro = ($record->location?->metroStations ?? collect())
            ->map(static fn (MetroStation $station): array => [
                'name' => $station->name,
                'line_name' => $station->line?->name,
                'line_color' => $station->line?->color,
                'latitude' => $station->latitude === null ? null : (float) $station->latitude,
                'longitude' => $station->longitude === null ? null : (float) $station->longitude,
            ])->values()->all();

        return response()->json([
            'data' => (new PublicVenueResource($record, $addressFormatter))->toArray($request),
            'map' => [
                // Public browser-side Yandex Maps key: also present on detail pages.
                'api_key' => config('integrations.yandex.api_key'),
                'nearby_url' => route('venues.nearby-map', $record->routeIdentifier()),
                'metro_stations' => $metro,
            ],
        ]);
    }
}
