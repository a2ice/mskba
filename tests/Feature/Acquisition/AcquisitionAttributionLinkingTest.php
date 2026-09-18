<?php

namespace Tests\Feature\Acquisition;

use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
use App\Modules\Acquisition\Domain\Enums\AcquisitionLandingTypeEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Modules\Acquisition\Domain\Models\AcquisitionVisit;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AcquisitionAttributionLinkingTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_onboarding_visit_is_linked_after_later_login(): void
    {
        $campaign = AcquisitionCampaign::query()->create([
            'public_code' => 'login-after-home',
            'name' => 'Login after campaign',
            'channel' => AcquisitionChannelEnum::CONTEXT_ADS,
            'landing_type' => AcquisitionLandingTypeEnum::HOME,
            'verification_radius_m' => 250,
            'location_verification_enabled' => false,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'username' => 'campaign_login_user',
            'password' => Hash::make('password'),
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->get(route('acquisition.entry', ['campaignCode' => $campaign->public_code]))
            ->assertRedirect(route('welcome'));

        $visit = AcquisitionVisit::query()->sole();
        $this->assertNull($visit->user_id);

        $this->post(route('auth.login'), [
            'login' => 'campaign_login_user',
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($user->canonical()->id, $visit->fresh()->user_id);
        $this->assertNotNull($visit->fresh()->linked_at);
    }
}
