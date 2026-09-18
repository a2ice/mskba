<?php

namespace App\Modules\Acquisition\Application\UseCases;

use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Modules\Acquisition\Domain\Models\AcquisitionVisit;

final class GetAdminAcquisitionCampaignStatsHandler
{
    /** @return array<string, mixed> */
    public function handle(AcquisitionCampaign $campaign): array
    {
        $visits = AcquisitionVisit::query()->where('campaign_id', $campaign->id);

        $locationStatuses = (clone $visits)
            ->selectRaw('location_status, COUNT(*) as total')
            ->groupBy('location_status')
            ->pluck('total', 'location_status')
            ->map(fn ($value): int => (int) $value)
            ->all();

        $personas = (clone $visits)
            ->whereNotNull('persona')
            ->selectRaw('persona, COUNT(*) as total')
            ->groupBy('persona')
            ->pluck('total', 'persona')
            ->map(fn ($value): int => (int) $value)
            ->all();

        return [
            'visits' => (clone $visits)->count(),
            'linked_visits' => (clone $visits)->whereNotNull('user_id')->count(),
            'linked_users' => (clone $visits)->whereNotNull('user_id')->distinct()->count('user_id'),
            'location_statuses' => $locationStatuses,
            'personas' => $personas,
            'recent_visits' => (clone $visits)
                ->with('user')
                ->latest('visited_at')
                ->limit(30)
                ->get(),
        ];
    }
}
