<?php

namespace App\Modules\Acquisition\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Acquisition\Application\Services\AcquisitionLandingResolver;
use App\Modules\Acquisition\Application\Services\AcquisitionTracker;
use App\Modules\Acquisition\Domain\Enums\AcquisitionLandingTypeEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AcquisitionCampaignEntryController extends Controller
{
    public function __invoke(
        Request $request,
        string $campaignCode,
        AcquisitionTracker $tracker,
        AcquisitionLandingResolver $landing,
    ): Response|RedirectResponse {
        $campaign = AcquisitionCampaign::query()
            ->with('venue.location.address')
            ->where('public_code', trim($campaignCode))
            ->firstOrFail();

        if (! $campaign->isAvailable()) {
            return ThemeResolver::page('onboarding.campaign-state', [
                'campaign' => $campaign,
                'campaignState' => $campaign->state(),
            ]);
        }

        if ($campaign->landing_type === AcquisitionLandingTypeEnum::ONBOARDING) {
            $target = route('acquisition.join', ['campaignCode' => $campaign->public_code]);
            $query = $request->getQueryString();

            return redirect()->to($query ? $target.'?'.$query : $target);
        }

        $tracker->capture($request, $campaign);

        return redirect()->to($landing->url($campaign));
    }
}
