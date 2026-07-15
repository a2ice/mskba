<?php

namespace App\Modules\Admin\Application\UseCases;

use App\Modules\Venue\Domain\Enums\VenueDuplicateStatusEnum;
use App\Modules\Venue\Domain\Models\VenueDuplicate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAdminVenueDuplicatesHandler
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = VenueDuplicate::query()
            ->with([
                'duplicateVenue.creatorActor.fingerprint',
                'duplicateVenue.creatorActor.user',
                'resolvedByUser',
                'venue.creatorActor.fingerprint',
                'venue.creatorActor.user',
            ])
            ->latest('id');

        $status = VenueDuplicateStatusEnum::tryFrom((string) ($filters['status'] ?? ''));
        if ($status !== null) {
            $query->where('status', $status->value);
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
