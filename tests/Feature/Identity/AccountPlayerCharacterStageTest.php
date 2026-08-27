<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountPlayerCharacterStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_character_stage_exposes_metric_three_scene_without_poc_or_svg_runtime_ui(): void
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
            ->assertSee('Соберите свой игровой образ')
            ->assertSee('Пол берётся из профиля: мужской')
            ->assertSee('200 см')
            ->assertSee('data-player-character-stage', false)
            ->assertSee('data-player-character-plot', false)
            ->assertSee('data-player-character-three', false)
            ->assertSee('data-player-character-height-marker', false)
            ->assertSee('data-player-character-error', false)
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
            ->assertDontSee('data-player-character-team', false)
            ->assertDontSee('data-player-character-choice="gender"', false)
            ->assertDontSee('name="character[gender]"', false)
            ->assertDontSee('PLAYER CHARACTER / 3D POC')
            ->assertDontSee('Сейчас проверяем 3D-базу')
            ->assertDontSee('3D готовится к загрузке')
            ->assertDontSee('data-player-character-svg-fallback', false)
            ->assertDontSee('data-renderer=', false);

        $content = $response->getContent();

        $this->assertSame(1, substr_count($content, 'account-player-character-layout'));
        $this->assertSame(1, substr_count($content, 'account-player-character-stage__plot'));
        $this->assertSame(1, substr_count($content, 'class="account-player-character-three" data-player-character-three'));
        $this->assertSame(1, substr_count($content, 'data-player-character-height-marker'));
        $this->assertSame(0, preg_match('/<svg\s+class="account-player-character-svg"\s+data-player-character-svg/s', $content));
    }

    public function test_active_player_team_home_colors_are_exposed_to_character_configurator(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->profile()->create(['gender' => UserGenderEnum::MALE]);
        $user->participationRoles(false)->create([
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);

        $this->actingAs($user)->post(route('teams.store'), [
            'name' => 'Альфа',
            'sport_types' => ['basketball'],
            'creator_sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
        ])->assertRedirect()->assertSessionHasNoErrors();

        Team::query()->where('name', 'Альфа')->firstOrFail()->update([
            'colors' => [
                'home_primary' => '#c55a02',
                'home_secondary' => '#21fd75',
            ],
        ]);

        $this->post(route('teams.store'), [
            'name' => 'Аа без цветов',
            'sport_types' => ['basketball'],
            'creator_sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->get(route('account.participation-role', UserParticipationRoleEnum::PLAYER->value))
            ->assertOk()
            ->assertSee('data-player-character-team', false)
            ->assertSee('data-team-name="Альфа"', false)
            ->assertSee('data-uniform-primary="#c55a02"', false)
            ->assertSee('data-uniform-accent="#21fd75"', false)
            ->assertSee('data-team-name="Аа без цветов"', false)
            ->assertSee('У команды не установлены домашние цвета. Используются штатные цвета формы.', false);
    }
}
