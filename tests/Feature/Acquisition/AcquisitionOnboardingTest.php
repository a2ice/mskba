<?php

namespace Tests\Feature\Acquisition;

use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
use App\Modules\Acquisition\Domain\Models\AcquisitionCampaign;
use App\Modules\Acquisition\Domain\Models\AcquisitionVisit;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AcquisitionOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_join_page_creates_direct_acquisition_visit(): void
    {
        $this->get(route('acquisition.join'))
            ->assertOk()
            ->assertSee('Привет и добро пожаловать на MSKBA.')
            ->assertSee('Присоединиться')
            ->assertSee('У меня уже есть аккаунт')
            ->assertSee('Игрок')
            ->assertSee('Тренер')
            ->assertSee('Представитель площадки')
            ->assertSee('Организатор');

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
        ])->assertRedirect(route('acquisition.success'));

        $this->assertAuthenticated();

        $user = User::query()->where('username', 'qr_player_191')->firstOrFail();
        $this->assertSame(UserRegistrationChannelEnum::SITE_FULL_REGISTRATION, $user->registration_channel);
        $this->assertTrue($user->participationRoles()->where('role', UserParticipationRoleEnum::PLAYER)->exists());

        $this->get(route('acquisition.success'))
            ->assertOk()
            ->assertSee('Добро пожаловать')
            ->assertSee('Роль · Игрок')
            ->assertSee('Доступные действия')
            ->assertSee('Найти игру или тренировку')
            ->assertSee('Понадобится подтверждённый аккаунт');

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
        ]);
        $campaign = $this->campaign('social-organizer-191', AcquisitionChannelEnum::SOCIAL);

        $this->actingAs($user)
            ->get(route('acquisition.join', ['campaignCode' => $campaign->public_code]))
            ->assertOk()
            ->assertSee('Моя роль')
            ->assertSee('организатор мероприятий')
            ->assertSee('Сохранить роли')
            ->assertSee('Продолжить');

        $visit = AcquisitionVisit::query()->sole();
        $this->assertSame($canonical->id, $visit->user_id);
        $this->assertSame(AcquisitionChannelEnum::SOCIAL, $visit->channel);
        $this->assertNull($visit->persona);
        $this->assertNotNull($visit->linked_at);

        $this->get(route('acquisition.success'))
            ->assertOk()
            ->assertSee('Роль · Организатор мероприятий')
            ->assertSee('Создать игру или тренировку');
    }

    public function test_authenticated_user_can_update_roles_inside_onboarding(): void
    {
        $user = User::factory()->create();
        $canonical = $user->canonical();
        $canonical->participationRoles()->create([
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
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

    public function test_success_groups_actions_for_each_active_role(): void
    {
        $user = User::factory()->create();
        $canonical = $user->canonical();

        foreach ([UserParticipationRoleEnum::PLAYER, UserParticipationRoleEnum::COACH] as $role) {
            $canonical->participationRoles()->create([
                'role' => $role,
                'status' => UserParticipationRoleStatusEnum::ACTIVE,
                'assigned_at' => now(),
            ]);
        }

        $this->actingAs($user)
            ->get(route('acquisition.success'))
            ->assertOk()
            ->assertSee('Роль · Игрок')
            ->assertSee('Роль · Тренер')
            ->assertSee('Найти игру или тренировку')
            ->assertSee('Открыть свою секцию');
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
