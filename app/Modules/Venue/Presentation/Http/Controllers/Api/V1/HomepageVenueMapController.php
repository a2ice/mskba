<?php

namespace App\Modules\Venue\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Location\Application\Services\AddressDisplayFormatter;
use App\Modules\Venue\Application\UseCases\ListPublicVenuesHandler;
use App\Modules\Venue\Domain\Enums\VenueSurfaceTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Presentation\Http\Resources\PublicVenueResource;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;

/**
 * Read-only, bounded homepage Yandex map feed. No unpublished venues,
 * private contact details or fabricated scores/availability.
 */
final class HomepageVenueMapController extends Controller
{
    public function __invoke(
        Request $request,
        ListPublicVenuesHandler $list,
        AddressDisplayFormatter $addressFormatter,
    ): JsonResponse {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => [
                'sometimes', 'required', 'string', 'max:512',
                function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        $id = Cursor::fromEncoded($value)?->parameter('id');
                        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
                            return;
                        }
                    } catch (\Throwable) {
                        // Malformed cursors are client errors.
                    }
                    $fail('The cursor is invalid.');
                },
            ],
        ]);

        $paginator = $list->handle((int) ($validated['limit'] ?? 100));
        $paginator->getCollection()->loadMissing([
            'location.address.district', 'courts', 'amenities',
        ]);
        $paginator->appends($request->only('limit'));

        $items = $paginator->getCollection()->map(function (Venue $venue) use ($request, $addressFormatter): array {
            $public = (new PublicVenueResource($venue, $addressFormatter))->toArray($request);
            $surfaces = $venue->courts->map(fn ($court): ?array => $court->surface_type instanceof VenueSurfaceTypeEnum
                ? ['value' => $court->surface_type->value, 'label' => $court->surface_type->label()]
                : null)->filter()->unique('value')->values()->all();

            return [
                'id' => $public['id'],
                'name' => $public['name'],
                'address' => $public['address'],
                'url' => $public['url'],
                'image_url' => $public['image_url'],
                'latitude' => $public['latitude'],
                'longitude' => $public['longitude'],
                'district' => $venue->location?->address?->district?->name,
                'surfaces' => $surfaces,
                'amenities' => $venue->amenities
                    ->filter(fn ($amenity): bool => (bool) $amenity->is_active)
                    ->pluck('name')->values()->all(),
            ];
        })->values()->all();

        return response()->json([
            'data' => $items,
            // This public browser key is already rendered on venue map pages.
            'maps_api_key' => (string) config('integrations.yandex.api_key', ''),
            'meta' => [
                'limit' => $paginator->perPage(),
                'has_more' => $paginator->hasMorePages(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
            ],
        ]);
    }
}
