<?php

namespace App\Modules\Admin\Application\UseCases;

use App\Modules\Venue\Domain\Enums\VenueDuplicateStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAdminVenuesHandler
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = Venue::query()
            ->with([
                'canonicalVenue',
                'creatorActor.user',
                'duplicateCandidates' => fn ($query) => $query->where('status', VenueDuplicateStatusEnum::PENDING),
                'duplicateOfCandidates' => fn ($query) => $query->where('status', VenueDuplicateStatusEnum::PENDING),
            ])
            ->withCount([
                'duplicateVenues',
            ])
            ->latest('id');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($query) use ($search): void {
                $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('alias', 'like', '%'.$search.'%')
                    ->orWhere('raw_address', 'like', '%'.$search.'%');
            });
        }

        $status = VenueStatusEnum::tryFrom((string) ($filters['status'] ?? ''));
        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $type = VenueTypeEnum::tryFrom((string) ($filters['type'] ?? ''));
        if ($type !== null) {
            $query->where('type', $type->value);
        }

        return $query
            ->paginate($this->perPage($filters))
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return min(max($perPage, 5), 50);
    }
}
