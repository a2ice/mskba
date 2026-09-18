<?php

namespace Tests\Feature\Acquisition;

use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
use App\Modules\Acquisition\Domain\Enums\AcquisitionLandingTypeEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Modules\Acquisition\Domain\Models\AcquisitionVisit;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AcquisitionCampaignEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_code_is_404(): void
    {
        $this->get(route('acquisition.entry', ['campaignCode' => 'missing-campaign']))
            ->assertNotFound();

        $this->assertSame(0, AcquisitionVisit::query()->count());
    }

    public function test_inactive_campaign_has_state_page_without_visit(): void
    {
        $campaign = $this->campaign(['public_code' => 'inactive-promo', 'is_active' => false]);

        $this->get(route('acquisition.entry', ['campaignCode' => $campaign->public_code]))
            ->assertOk()
            ->assertSee('Кампания сейчас неактивна')
            ->assertSee('Перейти на MSKBA');

        $this->assertSame(0, AcquisitionVisit::query()->count());
    }

    public function test_scheduled_campaign_has_start_page_without_visit(): void
    {
        $campaign = $this->campaign([
            'public_code' => 'future-promo',
            'starts_at' => now()->addDay()->setTime(12, 30),
        ]);

        $this->get(route('acquisition.entry', ['campaignCode' => $campaign->public_code]))
            ->assertOk()
            ->assertSee('Кампания ещё не началась')
            ->assertSee('12:30');

        $this->assertSame(0, AcquisitionVisit::query()->count());
    }

    public function test_ended_campaign_has_state_page_without_visit(): void
    {
        $campaign = $this->campaign([
            'public_code' => 'ended-promo',
            'ends_at' => now()->subHour(),
        ]);

        $this->get(route('acquisition.entry', ['campaignCode' => $campaign->public_code]))
            ->assertOk()
            ->assertSee('Кампания завершена');

        $this->assertSame(0, AcquisitionVisit::query()->count());
    }

    public function test_home_landing_tracks_then_redirects(): void
    {
        $campaign = $this->campaign([
            'public_code' => 'home-promo',
            'landing_type' => AcquisitionLandingTypeEnum::HOME,
            'channel' => AcquisitionChannelEnum::SOCIAL,
        ]);

        $this->get(route('acquisition.entry', [
            'campaignCode' => $campaign->public_code,
            'utm_source' => 'vk',
            'utm_medium' => 'social',
        ]))->assertRedirect(route('welcome'));

        $visit = AcquisitionVisit::query()->sole();
        $this->assertSame($campaign->id, $visit->campaign_id);
        $this->assertSame(AcquisitionChannelEnum::SOCIAL, $visit->channel);
        $this->assertSame('vk', $visit->source);
    }

    public function test_venue_landing_redirects_to_confirmed_venue(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $campaign = $this->campaign([
            'public_code' => 'venue-promo',
            'landing_type' => AcquisitionLandingTypeEnum::VENUE,
            'landing_target_id' => $venue->id,
        ]);

        $this->get(route('acquisition.entry', ['campaignCode' => $campaign->public_code]))
            ->assertRedirect(route('venues.show', $venue->routeIdentifier()));

        $this->assertSame($campaign->id, AcquisitionVisit::query()->sole()->campaign_id);
    }

    public function test_legacy_join_uses_configured_landing(): void
    {
        $campaign = $this->campaign([
            'public_code' => 'legacy-home',
            'landing_type' => AcquisitionLandingTypeEnum::HOME,
        ]);

        $this->get(route('acquisition.join', ['campaignCode' => $campaign->public_code]))
            ->assertRedirect(route('acquisition.entry', ['campaignCode' => $campaign->public_code]));

        $this->assertSame(0, AcquisitionVisit::query()->count());
    }

    /** @param array<string, mixed> $overrides */
    private function campaign(array $overrides = []): AcquisitionCampaign
    {
        return AcquisitionCampaign::query()->create(array_merge([
            'public_code' => 'campaign-entry-test',
            'name' => 'Campaign entry test',
            'channel' => AcquisitionChannelEnum::QR,
            'landing_type' => AcquisitionLandingTypeEnum::ONBOARDING,
            'verification_radius_m' => 250,
            'location_verification_enabled' => false,
            'is_active' => true,
        ], $overrides));
    }
}
