<?php

namespace App\Modules\Acquisition\Application\Services;

use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;

final readonly class AcquisitionFlyerContextFactory
{
    public function __construct(private AcquisitionQrCodeRenderer $qr) {}

    /** @return array<string, mixed> */
    public function make(AcquisitionCampaign $campaign): array
    {
        $campaign->loadMissing('venue.location.address');

        $logoPath = public_path('images/logo-header-cropped.png');
        $logoDataUri = is_file($logoPath)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;

        $qrSvg = $this->qr->svg($campaign);

        return [
            'campaign' => $campaign,
            'venue' => $campaign->venue,
            'joinUrl' => $this->qr->joinUrl($campaign),
            'qrDataUri' => 'data:image/svg+xml;base64,'.base64_encode($qrSvg),
            'logoDataUri' => $logoDataUri,
            'placement' => trim((string) data_get($campaign->metadata, 'placement', '')),
        ];
    }
}
