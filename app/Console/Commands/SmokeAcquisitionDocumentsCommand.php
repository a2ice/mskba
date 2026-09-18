<?php

namespace App\Console\Commands;

use App\Modules\Acquisition\Application\Services\AcquisitionQrCodeRenderer;
use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
use App\Modules\Acquisition\Domain\Enums\AcquisitionLandingTypeEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Modules\Template\Application\Contracts\DocumentRenderer;
use App\Modules\Template\Application\Contracts\TemplateRenderer;
use App\Modules\Template\Domain\Enums\DocumentFormatEnum;
use Illuminate\Console\Command;
use Throwable;

final class SmokeAcquisitionDocumentsCommand extends Command
{
    protected $signature = 'acquisition:documents:smoke';

    protected $description = 'Проверить QR → HTML template → PDF pipeline без записи в БД';

    public function handle(
        AcquisitionQrCodeRenderer $qr,
        TemplateRenderer $templates,
        DocumentRenderer $documents,
    ): int {
        try {
            $campaign = new AcquisitionCampaign;
            $campaign->forceFill([
                'public_code' => 'document-smoke',
                'name' => 'MSKBA document smoke',
                'channel' => AcquisitionChannelEnum::QR,
                'landing_type' => AcquisitionLandingTypeEnum::ONBOARDING,
                'verification_radius_m' => 250,
                'location_verification_enabled' => false,
                'is_active' => true,
            ]);

            $svg = $qr->svg($campaign);
            if ($svg === '' || ! str_contains($svg, '<svg')) {
                throw new \RuntimeException('QR renderer returned invalid SVG.');
            }

            $joinUrl = $qr->joinUrl($campaign);
            $html = $templates->render('acquisition.flyer.a4', [
                'campaign' => $campaign,
                'venue' => null,
                'joinUrl' => $joinUrl,
                'qrDataUri' => 'data:image/svg+xml;base64,'.base64_encode($svg),
                'logoDataUri' => null,
                'placement' => '',
            ]);

            if ($html === '' || ! str_contains($html, $joinUrl)) {
                throw new \RuntimeException('Flyer template returned unexpected HTML.');
            }

            $pdf = $documents->render($html, DocumentFormatEnum::PDF);
            if ($pdf->contents === '' || ! str_starts_with($pdf->contents, '%PDF')) {
                throw new \RuntimeException('Document renderer returned invalid PDF.');
            }

            $this->info(sprintf(
                'Acquisition document pipeline OK: QR %d B, HTML %d B, PDF %d B.',
                strlen($svg),
                strlen($html),
                strlen($pdf->contents),
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error(sprintf(
                '%s: %s',
                $exception::class,
                $exception->getMessage(),
            ));

            return self::FAILURE;
        }
    }
}
