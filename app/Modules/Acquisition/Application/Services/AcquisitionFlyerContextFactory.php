<?php

namespace App\Modules\Acquisition\Application\Services;

use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Modules\Template\Application\Services\TrustedTemplateRegistry;

final readonly class AcquisitionFlyerContextFactory
{
    public function __construct(
        private AcquisitionQrCodeRenderer $qr,
        private TrustedTemplateRegistry $templates,
    ) {}

    /** @return array<string, mixed> */
    public function make(AcquisitionCampaign $campaign): array
    {
        $campaign->loadMissing('venue.location.address');

        $templateKey = (string) data_get(
            $campaign->metadata,
            'template_key',
            'acquisition.flyer.a4',
        );
        $qrSvg = $this->qr->svg($campaign);

        return [
            'campaign' => $campaign,
            'venue' => $campaign->venue,
            'joinUrl' => $this->qr->joinUrl($campaign),
            'qrDataUri' => 'data:image/svg+xml;base64,'.base64_encode($qrSvg),
            'logoDataUri' => $this->assetDataUri($this->templates->assetPath($templateKey, 'logo')),
            'heroImageDataUri' => $this->assetDataUri($this->templates->assetPath($templateKey, 'hero_image')),
            'placement' => trim((string) data_get($campaign->metadata, 'placement', '')),
        ];
    }

    private function assetDataUri(?string $relativePath): ?string
    {
        if ($relativePath === null) {
            return null;
        }

        $path = public_path(ltrim($relativePath, '/'));
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if (! is_string($contents) || $contents === '') {
            return null;
        }

        $mimeType = mime_content_type($path);
        if (! is_string($mimeType) || $mimeType === '') {
            $mimeType = 'application/octet-stream';
        }

        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }
}
