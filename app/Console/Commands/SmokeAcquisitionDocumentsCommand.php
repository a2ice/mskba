<?php

namespace App\Console\Commands;

use App\Modules\Acquisition\Application\Services\AcquisitionFlyerContextFactory;
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

    protected $description = 'Проверить QR → flyer context/assets → HTML template → PDF без записи в БД';

    public function handle(
        AcquisitionFlyerContextFactory $contextFactory,
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
                'metadata' => [
                    'template_key' => 'acquisition.flyer.a4',
                ],
            ]);

            $context = $contextFactory->make($campaign);

            $qrDataUri = $context['qrDataUri'] ?? null;
            if (! is_string($qrDataUri) || ! str_starts_with($qrDataUri, 'data:image/svg+xml;base64,')) {
                throw new \RuntimeException('Flyer context did not provide a QR data URI.');
            }

            $heroImageDataUri = $context['heroImageDataUri'] ?? null;
            if (! is_string($heroImageDataUri) || ! str_starts_with($heroImageDataUri, 'data:image/png;base64,')) {
                throw new \RuntimeException('Flyer context did not provide the hero image asset.');
            }

            $joinUrl = $context['joinUrl'] ?? null;
            if (! is_string($joinUrl) || $joinUrl === '') {
                throw new \RuntimeException('Flyer context did not provide campaign URL.');
            }

            $html = $templates->render('acquisition.flyer.a4', $context);
            if (
                $html === ''
                || ! str_contains($html, $joinUrl)
                || ! str_contains($html, 'class="hero-art"')
            ) {
                throw new \RuntimeException('Flyer template returned unexpected HTML.');
            }

            $pdf = $documents->render($html, DocumentFormatEnum::PDF);
            if ($pdf->contents === '' || ! str_starts_with($pdf->contents, '%PDF')) {
                throw new \RuntimeException('Document renderer returned invalid PDF.');
            }

            $this->info(sprintf(
                'Acquisition document pipeline OK: QR context %d B, hero asset %d B, HTML %d B, PDF %d B.',
                strlen($qrDataUri),
                strlen($heroImageDataUri),
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
