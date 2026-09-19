<?php

namespace Tests\Feature\Acquisition;

use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Modules\Acquisition\Domain\Models\AcquisitionVisit;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AcquisitionOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_join_page_creates_direct_acquisition_visit(): void
    {
        $this->get(route('acquisition.join'))->assertOk();

        $visit = AcquisitionVisit::query()->sole();

        $this->assertSame(AcquisitionChannelEnum::DIRECT, $visit->channel);
        $this->assertNull($visit->user_id);
        $this->assertSame('not_requested', $visit->location_status);
    }

    public function test_campaign_visit_keeps_marketing_channel_and_selected_persona(): void
    {
        $campaign = $this->campaign('school-1794-a4', AcquisitionChannelEnum::QR);

        $this->get(route('acquisition.join', ['campaignCode' => $campaign->public_code]))->assertOk();
        $this->postJson(route('acquisition.persona'), ['persona' => 'coach'])
            ->assertOk()
            ->assertJsonPath('persona', 'coach')
            ->assertJsonPath('role', 'coach')
            ->assertJsonPath('needs_profile_details', true);

        $visit = AcquisitionVisit::query()->sole();

        $this->assertSame($campaign->id, $visit->campaign_id);
        $this->assertSame(AcquisitionChannelEnum::QR, $visit->channel);
        $this->assertSame('coach', $visit->persona->value);
    }

    public function test_registration_from_campaign_links_visit_to_new_canonical_user(): void
    {
        $campaign = $this->campaign('court-promo-191', AcquisitionChannelEnum::QR);
        $password = 'A'.strtolower(Str::random(8)).'1!';

        $this->get(route('acquisition.join', ['campaignCode' => $campaign->public_code]))->assertOk();
        $this->postJson(route('acquisition.persona'), ['persona' => 'player'])->assertOk();

        $this->post(route('auth.register'), [
            'username' => 'qr_player_191',
            'password' => $password,
            'password_confirmation' => $password,
            'role' => 'player',
            'first_name' => 'Иван',
            'last_name' => 'Игроков',
            'birth_date' => '1995-05-15',
            'gender' => 'male',
            'privacy_consent' => '1',
            'redirect_to' => route('acquisition.success', [], false),
        ])->assertRedirect(route('account.privacy.distribution'));

        $this->assertAuthenticated();
        $this->assertSame(route('acquisition.success'), session('privacy.distribution.return_to'));

        $user = User::query()->where('username', 'qr_player_191')->firstOrFail();
        $this->assertSame(UserRegistrationChannelEnum::SITE_FULL_REGISTRATION, $user->registration_channel);
        $this->assertTrue($user->participationRoles()->where('role', UserParticipationRoleEnum::PLAYER)->exists());

        $this->put(route('account.privacy.distribution.update'), [
            'action' => 'save',
            'public' => [
                'profile' => '1',
                'avatar' => '1',
                'role_player' => '1',
                'player_characteristics' => '1',
                'player_teams' => '1',
                'player_games' => '1',
            ],
            'distribution_consent' => '1',
        ])->assertRedirect(route('acquisition.success'));

        $this->get(route('acquisition.success'))
            ->assertOk()
            ->assertViewHas('activeRoles', fn (Collection $roles): bool => $roles->contains(UserParticipationRoleEnum::PLAYER));

        $visit = AcquisitionVisit::query()->sole();
        $this->assertSame($user->canonical()->id, $visit->user_id);
        $this->assertSame(AcquisitionChannelEnum::QR, $visit->channel);
        $this->assertSame('player', $visit->persona->value);
        $this->assertNotNull($visit->linked_at);
    }

    public function test_already_authenticated_user_is_linked_and_can_review_existing_roles(): void
    {
        $user = User::factory()->create();
        $canonical = $user->canonical();
        $canonical->participationRoles()->create([
            'role' => UserParticipationRoleEnum::ORGANIZER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $canonical->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
        $campaign = $this->campaign('social-organizer-191', AcquisitionChannelEnum::SOCIAL);

        $this->actingAs($user)
            ->get(route('acquisition.join', ['campaignCode' => $campaign->public_code]))
            ->assertOk()
            ->assertViewHas('authenticatedUser', fn (User $viewUser): bool => $viewUser->id === $canonical->id)
            ->assertViewHas('activeRoleValues', fn (array $roles): bool => in_array(UserParticipationRoleEnum::ORGANIZER->value, $roles, true));

        $visit = AcquisitionVisit::query()->sole();
        $this->assertSame($canonical->id, $visit->user_id);
        $this->assertSame(AcquisitionChannelEnum::SOCIAL, $visit->channel);
        $this->assertNull($visit->persona);
        $this->assertNotNull($visit->linked_at);

        $this->get(route('acquisition.success'))
            ->assertOk()
            ->assertViewHas('activeRoles', fn (Collection $roles): bool => $roles->contains(UserParticipationRoleEnum::ORGANIZER));
    }

    public function test_authenticated_resume_reuses_visit_created_before_login(): void
    {
        $this->get(route('acquisition.join'))->assertOk();
        $originalVisit = AcquisitionVisit::query()->sole();
        $this->assertSame(AcquisitionChannelEnum::DIRECT, $originalVisit->channel);
        $this->assertNull($originalVisit->user_id);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Referer', route('acquisition.join'))
            ->get(route('acquisition.join', ['resume' => 1]))
            ->assertOk();

        $this->assertSame(1, AcquisitionVisit::query()->count());
        $this->assertSame($user->canonical()->id, $originalVisit->fresh()->user_id);
        $this->assertNotNull($originalVisit->fresh()->linked_at);
    }

    public function test_authenticated_user_can_update_roles_inside_onboarding(): void
    {
        $user = User::factory()->create();
        $canonical = $user->canonical();
        $canonical->participationRoles()->create([
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $canonical->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);

        $this->actingAs($user)
            ->patchJson(route('acquisition.roles.update'), [
                'roles' => [
                    UserParticipationRoleEnum::PLAYER->value => false,
                    UserParticipationRoleEnum::COACH->value => true,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('roles.0.value', UserParticipationRoleEnum::COACH->value)
            ->assertJsonPath('roles.0.label', 'Тренер');

        $this->assertFalse($canonical->fresh()->hasActiveRole(UserParticipationRoleEnum::PLAYER->value));
        $this->assertTrue($canonical->fresh()->hasActiveRole(UserParticipationRoleEnum::COACH->value));
    }

    public function test_success_exposes_all_active_roles_to_the_view(): void
    {
        $user = User::factory()->create();
        $canonical = $user->canonical();

        foreach ([UserParticipationRoleEnum::PLAYER, UserParticipationRoleEnum::COACH] as $role) {
            $canonical->participationRoles()->create([
                'role' => $role,
                'status' => UserParticipationRoleStatusEnum::ACTIVE,
                'assigned_at' => now(),
                'assigned_by' => $canonical->id,
                'assigner' => UserParticipationRoleAssignerEnum::USER,
            ]);
        }

        $this->actingAs($user)
            ->get(route('acquisition.success'))
            ->assertOk()
            ->assertViewHas('activeRoles', function (Collection $roles): bool {
                return $roles->contains(UserParticipationRoleEnum::PLAYER)
                    && $roles->contains(UserParticipationRoleEnum::COACH)
                    && $roles->count() === 2;
            });
    }

    public function test_utm_medium_can_attribute_generic_join_to_context_ads(): void
    {
        $this->get(route('acquisition.join', [
            'utm_source' => 'yandex',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'moscow_basketball',
        ]))->assertOk();

        $visit = AcquisitionVisit::query()->sole();
        $this->assertSame(AcquisitionChannelEnum::CONTEXT_ADS, $visit->channel);
        $this->assertSame('yandex', $visit->source);
        $this->assertSame('cpc', $visit->medium);
        $this->assertSame('moscow_basketball', $visit->campaign_name);
    }

    private function campaign(string $code, AcquisitionChannelEnum $channel): AcquisitionCampaign
    {
        return AcquisitionCampaign::query()->create([
            'public_code' => $code,
            'name' => 'Acquisition test campaign',
            'channel' => $channel,
            'verification_radius_m' => 250,
            'is_active' => true,
        ]);
    }
}
