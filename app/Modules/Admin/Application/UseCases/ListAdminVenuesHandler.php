<?php

namespace App\Modules\Admin\Application\UseCases;

use App\Modules\Admin\Application\Services\VenueRevisionDiffBuilder;
use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueDuplicateStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAdminVenuesHandler
{
    public function __construct(
        private readonly VenueRevisionDiffBuilder $revisionDiffs,
    ) {}

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
                'moderationRequests.messages.sender.user',
                'moderationRequests.venueRevision.media',
                'media' => fn ($query) => $query->where('collection', 'gallery'),
                'location.address',
                'location.metroStations.line',
                'tags',
                'characteristics',
                'amenities',
            ])
            ->withCount([
                'duplicateVenues',
            ])
            ->latest('id');

        if (($filters['deleted'] ?? '') === '1') {
            $query->onlyTrashed();
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($query) use ($search): void {
                $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('alias', 'like', '%'.$search.'%')
                    ->orWhere('raw_address', 'like', '%'.$search.'%');
            });
        }

        $statusFilter = (string) ($filters['status'] ?? '');
        $status = VenueStatusEnum::tryFrom($statusFilter);
        if ($statusFilter === 'pending_moderation') {
            $query->whereHas('moderationRequests', fn ($query) => $query
                ->where('status', ModerationRequestStatusEnum::PENDING->value));
        } elseif ($status !== null) {
            $query->where('status', $status->value);
        }

        $type = VenueTypeEnum::tryFrom((string) ($filters['type'] ?? ''));
        if ($type !== null) {
            $query->where('type', $type->value);
        }

        $venues = $query
            ->paginate($this->perPage($filters))
            ->withQueryString();

        foreach ($venues as $venue) {
            foreach ($venue->moderationRequests as $moderationRequest) {
                if (
                    $moderationRequest->status === ModerationRequestStatusEnum::PENDING
                    && $moderationRequest->venueRevision !== null
                ) {
                    $moderationRequest->setAttribute(
                        'revision_diff',
                        $this->revisionDiffs->build($venue, $moderationRequest->venueRevision),
                    );
                }
            }
        }

        return $venues;
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
