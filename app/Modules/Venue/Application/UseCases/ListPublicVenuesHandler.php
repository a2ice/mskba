<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

final class ListPublicVenuesHandler
{
    public function handle(int $limit, ?Cursor $cursor = null): CursorPaginator
    {
        return $this->publicQuery()
            ->orderBy('id')
            ->cursorPaginate($limit, ['*'], 'cursor', $cursor);
    }

    public function find(int $id): ?Venue
    {
        return $this->publicQuery()->find($id);
    }

    private function publicQuery(): Builder
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
            ]);
    }
}
