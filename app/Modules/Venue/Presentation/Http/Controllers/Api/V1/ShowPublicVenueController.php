<?php

namespace App\Modules\Venue\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Location\Application\Services\AddressDisplayFormatter;
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

        return response()->json([
            'data' => (new PublicVenueResource($record, $addressFormatter))->toArray($request),
        ]);
    }
}
