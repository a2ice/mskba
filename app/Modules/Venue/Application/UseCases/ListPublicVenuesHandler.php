<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Pagination\CursorPaginator;

final class ListPublicVenuesHandler
{
    public function handle(int $limit): CursorPaginator
    {
        return Venue::query()
            ->where('status', VenueStatusEnum::CONFIRMED->value)
            ->with([
                'location.address',
                'media' => fn ($query) => $query
                    ->where('collection', 'gallery')
                    ->orderByDesc('is_featured')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            // The primary key is unique, non-null and indexed: a stable cursor key.
            ->orderBy('id')
            ->cursorPaginate($limit);
    }
}
