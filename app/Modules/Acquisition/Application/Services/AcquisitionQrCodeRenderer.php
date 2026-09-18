<?php

namespace App\Modules\Acquisition\Application\Services;

use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use RuntimeException;
use Symfony\Component\Process\Process;

final class AcquisitionQrCodeRenderer
{
    public function joinUrl(AcquisitionCampaign $campaign): string
    {
        return route('acquisition.entry', ['campaignCode' => $campaign->public_code]);
    }

    public function svg(AcquisitionCampaign $campaign): string
    {
        return $this->render($campaign, 'SVG');
    }

    public function png(AcquisitionCampaign $campaign): string
    {
        return $this->render($campaign, 'PNG');
    }

    private function render(AcquisitionCampaign $campaign, string $type): string
    {
        $process = new Process([
            (string) config('document-templates.qr.binary', 'qrencode'),
            '-t',
            $type,
            '-o',
            '-',
            '-m',
            '2',
            '-s',
            '8',
            $this->joinUrl($campaign),
        ]);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful() || $process->getOutput() === '') {
            throw new RuntimeException('Не удалось сформировать QR-код.');
        }

        return $process->getOutput();
    }
}
