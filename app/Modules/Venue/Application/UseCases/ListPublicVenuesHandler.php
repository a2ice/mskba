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
            // Resolve inherited policies in SQL *before* cursor pagination;
            // a venue can qualify through a single matching open court.
            ->when($filters['access'] ?? null, fn (Builder $q, string $access): Builder => $q
                ->where('venues.operational_status', 'active')
                ->whereHas('courts', function (Builder $courts) use ($access): void {
                    $courts->where(function (Builder $state): void {
                        $state->whereNull('operational_status')->orWhere('operational_status', 'active');
                    });

                    if ($access === 'approval') {
                        $courts->whereRaw(
                            'COALESCE(venue_courts.requires_booking_approval, venues.requires_booking_approval, FALSE) = TRUE'
                        );

                        return;
                    }

                    $courts->whereRaw(
                        'COALESCE(venue_courts.requires_payment, '
                        .'(SELECT p.requires_payment FROM venue_booking_policies p '
                        .'WHERE p.venue_id = venues.id AND p.active_marker = TRUE AND p.is_enabled = TRUE '
                        .'ORDER BY p.id DESC LIMIT 1), venues.requires_payment) = ?',
                        [$access === 'paid'],
                    );
                }));

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
                'courts',
                'activeBookingPolicy',
                'media' => fn ($query) => $query
                    ->where('collection', 'gallery')
                    ->orderByDesc('is_featured')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ]);
    }
}
