<?php

namespace App\Modules\Acquisition\Application\UseCases;

use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Modules\Acquisition\Domain\Models\AcquisitionVisit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAdminAcquisitionCampaignsHandler
{
    /** @param array<string, mixed> $filters */
    public function handle(array $filters): LengthAwarePaginator
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $channel = trim((string) ($filters['channel'] ?? ''));
        $active = $filters['active'] ?? '';

        return AcquisitionCampaign::query()
            ->with('venue.location.address')
            ->withCount([
                'visits',
                'visits as linked_visits_count' => fn ($query) => $query->whereNotNull('user_id'),
                'visits as verified_visits_count' => fn ($query) => $query->where('location_status', 'verified'),
            ])
            ->addSelect([
                'linked_users_count' => AcquisitionVisit::query()
                    ->selectRaw('COUNT(DISTINCT user_id)')
                    ->whereColumn('campaign_id', 'acquisition_campaigns.id')
                    ->whereNotNull('user_id'),
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($nested) use ($like): void {
                    $nested
                        ->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(public_code) LIKE ?', [$like])
                        ->orWhereHas('venue', fn ($venue) => $venue->whereRaw('LOWER(name) LIKE ?', [$like]));
                });
            })
            ->when($channel !== '', fn ($query) => $query->where('channel', $channel))
            ->when($active === '1', fn ($query) => $query->where('is_active', true))
            ->when($active === '0', fn ($query) => $query->where('is_active', false))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
    }
}
