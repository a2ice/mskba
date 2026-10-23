<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

final class ListPublicVenuesHandler
{
    /**
     * @param array{type?: string|null, operational_status?: string|null, access?: string|null, search?: string|null} $filters
     */
    public function handle(int $limit, ?Cursor $cursor = null, array $filters = []): CursorPaginator
    {
        $query = $this->publicQuery()
            ->when($filters['type'] ?? null, fn (Builder $q, string $type): Builder => $q->where('type', $type))
            ->when($filters['operational_status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('operational_status', $status))
            ->when(($filters['access'] ?? null) === 'free', fn (Builder $q): Builder => $q->where('requires_payment', false)->where('requires_booking_approval', false))
            ->when(($filters['access'] ?? null) === 'paid', fn (Builder $q): Builder => $q->where('requires_payment', true))
            ->when(($filters['access'] ?? null) === 'approval', fn (Builder $q): Builder => $q->where('requires_booking_approval', true));

        // Match the web catalog: all whitespace-separated search terms must match.
        // Escape LIKE wildcards so user-supplied % and _ are treated literally.
        $terms = preg_split('/\\s+/u', trim((string) ($filters['search'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($terms as $term) {
            $needle = '%'.addcslashes($term, '%_\\\\').'%';
            $query->where(fn (Builder $match): Builder => $match
                ->whereLike('name', $needle)
                ->orWhereLike('raw_address', $needle)
                ->orWhereLike('short_description', $needle));
        }

        // All filtering happens in SQL BEFORE cursor pagination.
        return $query->orderBy('id')->cursorPaginate($limit, ['*'], 'cursor', $cursor);
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
