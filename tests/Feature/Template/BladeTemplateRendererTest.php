<?php

namespace Tests\Feature\Template;

use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
use App\Modules\Acquisition\Domain\Enums\AcquisitionLandingTypeEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Modules\Template\Application\Contracts\TemplateRenderer;
use Tests\TestCase;

final class BladeTemplateRendererTest extends TestCase
{
    public function test_trusted_template_key_with_dots_is_resolved_literally(): void
    {
        $campaign = new AcquisitionCampaign;
        $campaign->forceFill([
            'public_code' => 'template-test',
            'name' => 'Template test campaign',
            'channel' => AcquisitionChannelEnum::QR,
            'landing_type' => AcquisitionLandingTypeEnum::ONBOARDING,
            'verification_radius_m' => 250,
            'location_verification_enabled' => false,
            'is_active' => true,
        ]);

        $html = app(TemplateRenderer::class)->render('acquisition.flyer.a4', [
            'campaign' => $campaign,
            'venue' => null,
            'joinUrl' => 'https://mskba.test/go/template-test',
            'qrDataUri' => 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            'logoDataUri' => null,
            'placement' => '',
        ]);

        $this->assertStringContainsString('Template test campaign', $html);
        $this->assertStringContainsString('https://mskba.test/go/template-test', $html);
    }
}
