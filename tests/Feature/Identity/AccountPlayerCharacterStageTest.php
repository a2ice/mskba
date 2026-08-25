<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountPlayerCharacterStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_character_stage_exposes_three_renderer_and_profile_gender_contract(): void
    {
        $user = User::factory()->create();
        $user->profile()->create([
            'gender' => UserGenderEnum::MALE,
        ]);
        $user->participationRoles(false)->create([
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
        $user->playerProfile()->create([
            'height_cm' => 191,
            'weight_kg' => 88,
            'body_type' => PlayerBodyTypeEnum::ATHLETIC,
        ]);

        $response = $this->actingAs($user)
            ->get(route('account.participation-role', UserParticipationRoleEnum::PLAYER->value))
            ->assertOk()
            ->assertSee('Масштаб сцены: 200 × 250 см.')
            ->assertSee('Соберите свой игровой образ')
            ->assertSee('Пол берётся из профиля: мужской')
            ->assertSee('data-player-character-stage', false)
            ->assertSee('data-player-character-plot', false)
            ->assertSee('data-player-character-three', false)
            ->assertSee('data-player-character-svg', false)
            ->assertSee('data-renderer="three-pending"', false)
            ->assertSee('data-gender="male"', false)
            ->assertSee('data-height="191"', false)
            ->assertSee('data-weight="88"', false)
            ->assertSee('data-body-type="athletic"', false)
            ->assertSee('data-skin-tone="warm"', false)
            ->assertSee('data-hairstyle="male_fade"', false)
            ->assertSee('data-hair-color="dark_brown"', false)
            ->assertSee('data-facial-hair="none"', false)
            ->assertSee('data-uniform-kit="mskba_home"', false)
            ->assertSee('data-player-character-input="height"', false)
            ->assertSee('data-player-character-input="weight"', false)
            ->assertSee('data-player-character-input="body-type"', false)
            ->assertSee('data-player-character-field="skin-tone"', false)
            ->assertSee('data-player-character-field="hairstyle"', false)
            ->assertSee('data-player-character-field="uniform-kit"', false)
            ->assertDontSee('data-player-character-choice="gender"', false)
            ->assertDontSee('name="character[gender]"', false);

        $content = $response->getContent();

        $this->assertSame(1, substr_count($content, 'account-player-character-layout'));
        $this->assertSame(1, substr_count($content, 'account-player-character-stage__plot'));
        $this->assertSame(1, substr_count($content, 'class="account-player-character-three" data-player-character-three'));
        $this->assertSame(1, preg_match('/<svg\s+class="account-player-character-svg"\s+data-player-character-svg/s', $content));
    }
}
