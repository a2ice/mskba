<?php

namespace Tests\Feature\Acquisition;

use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
use App\Modules\Acquisition\Domain\Enums\AcquisitionLandingTypeEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Modules\Acquisition\Domain\Models\AcquisitionVisit;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminAcquisitionCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_venue_backed_qr_campaign(): void
    {
        $admin = $this->admin();
        $venue = Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($admin, null)->id,
            'name' => 'Школа 1794',
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.acquisition.store'), [
                'name' => 'Листовка у школы 1794',
                'public_code' => '',
                'channel' => AcquisitionChannelEnum::QR->value,
                'landing_type' => AcquisitionLandingTypeEnum::ONBOARDING->value,
                'venue_id' => $venue->id,
                'location_verification_enabled' => '1',
                'verification_radius_m' => 250,
                'is_active' => '1',
                'placement' => 'Стенд у главного входа',
                'notes' => 'Первая тестовая точка',
            ])
            ->assertRedirect();

        $campaign = AcquisitionCampaign::query()->sole();

        $this->assertSame('Листовка у школы 1794', $campaign->name);
        $this->assertSame(AcquisitionChannelEnum::QR, $campaign->channel);
        $this->assertSame($venue->id, $campaign->venue_id);
        $this->assertSame(AcquisitionLandingTypeEnum::ONBOARDING, $campaign->landing_type);
        $this->assertTrue($campaign->location_verification_enabled);
        $this->assertTrue($campaign->is_active);
        $this->assertMatchesRegularExpression('/^[a-z0-9_-]{2,64}$/', $campaign->public_code);
        $this->assertSame('Стенд у главного входа', data_get($campaign->metadata, 'placement'));
        $this->assertSame('Первая тестовая точка', data_get($campaign->metadata, 'notes'));
        $this->assertSame('acquisition.flyer.a4', data_get($campaign->metadata, 'template_key'));
    }

    public function test_admin_campaign_page_reports_repeat_visits_and_unique_users(): void
    {
        $admin = $this->admin();
        $linkedUser = User::factory()->create();
        $campaign = AcquisitionCampaign::query()->create([
            'public_code' => 'school-1794-a4',
            'name' => 'Школа 1794',
            'channel' => AcquisitionChannelEnum::QR,
            'landing_type' => AcquisitionLandingTypeEnum::ONBOARDING,
            'verification_radius_m' => 250,
            'location_verification_enabled' => false,
            'is_active' => true,
        ]);

        foreach ([
            ['persona' => 'player', 'location_status' => 'verified', 'distance_to_venue_m' => 25],
            ['persona' => 'coach', 'location_status' => 'not_requested', 'distance_to_venue_m' => null],
        ] as $row) {
            AcquisitionVisit::query()->create([
                'campaign_id' => $campaign->id,
                'user_id' => $linkedUser->id,
                'channel' => AcquisitionChannelEnum::QR,
                'persona' => $row['persona'],
                'landing_path' => '/join/school-1794-a4',
                'location_status' => $row['location_status'],
                'distance_to_venue_m' => $row['distance_to_venue_m'],
                'visited_at' => now(),
                'linked_at' => now(),
            ]);
        }

        $response = $this
            ->actingAs($admin)
            ->get(route('admin.acquisition.edit', $campaign))
            ->assertOk();

        $stats = $response->viewData('stats');

        $this->assertSame(2, $stats['visits']);
        $this->assertSame(2, $stats['linked_visits']);
        $this->assertSame(1, $stats['linked_users']);
        $this->assertSame(1, $stats['location_statuses']['verified']);
        $this->assertSame(1, $stats['location_statuses']['not_requested']);
        $this->assertSame(1, $stats['personas']['player']);
        $this->assertSame(1, $stats['personas']['coach']);
    }

    public function test_regular_user_cannot_access_acquisition_admin(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);

        $this
            ->actingAs($user)
            ->get(route('admin.acquisition.index'))
            ->assertForbidden();
    }

    private function admin(): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
    }
}
