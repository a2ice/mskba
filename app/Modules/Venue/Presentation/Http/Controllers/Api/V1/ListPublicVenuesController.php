<?php

namespace App\Modules\Venue\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Location\Application\Services\AddressDisplayFormatter;
use App\Modules\Venue\Application\UseCases\ListPublicVenuesHandler;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Presentation\Http\Resources\PublicVenueResource;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\Rule;

final class ListPublicVenuesController extends Controller
{
    public function __invoke(
        Request $request,
        ListPublicVenuesHandler $listPublicVenues,
        AddressDisplayFormatter $addressFormatter,
    ): JsonResponse {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            // The same filters and values as the public web venue catalog.
            'type' => ['sometimes', 'nullable', Rule::enum(VenueTypeEnum::class)],
            'operational_status' => ['sometimes', 'nullable', Rule::enum(VenueOperationalStatusEnum::class)],
            'access' => ['sometimes', 'nullable', Rule::in(['free', 'paid', 'approval'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cursor' => [
                'sometimes', 'required', 'string', 'max:512',
                function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        $cursor = Cursor::fromEncoded($value);
                        $id = $cursor?->parameter('id');

                        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
                            return;
                        }
                    } catch (\Throwable) {
                        // An invalid cursor is a client error, not the first page.
                    }

                    $fail('The cursor is invalid.');
                },
            ],
        ]);

        $filters = array_intersect_key($validated, array_flip(['type', 'operational_status', 'access', 'search']));
        $paginator = $listPublicVenues->handle((int) ($validated['limit'] ?? 30), filters: $filters);
        // Keep active filters on every next-page URL (and cursor pagination).
        $paginator->appends($request->only('limit', 'type', 'operational_status', 'access', 'search'));

        return response()->json([
            'data' => array_map(
                fn (Venue $venue): array => (new PublicVenueResource($venue, $addressFormatter))->toArray($request),
                $paginator->items(),
            ),
            'meta' => [
                'limit' => $paginator->perPage(),
                'has_more' => $paginator->hasMorePages(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
            ],
            'links' => [
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }
}
