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

    public function test_player_character_stage_uses_image_silhouette_flow_without_three_runtime_ui(): void
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
            ->assertSee('Настроить игровой образ')
            ->assertSee('Пол берётся из профиля: мужской')
            ->assertSee('200 см')
            ->assertSee('data-player-character-stage', false)
            ->assertSee('data-player-character-silhouette', false)
            ->assertSee('data-player-character-modal', false)
            ->assertSee('data-player-character-generated', false)
            ->assertSee('data-player-character-loading', false)
            ->assertSee('data-player-character-height-marker', false)
            ->assertSee('data-gender="male"', false)
            ->assertSee('data-player-character-input="height"', false)
            ->assertSee('data-player-character-input="weight"', false)
            ->assertSee('data-player-character-input="body-type"', false)
            ->assertSee('data-player-character-field="skin-tone"', false)
            ->assertSee('data-player-character-field="hairstyle"', false)
            ->assertDontSee('data-player-character-three', false)
            ->assertDontSee('PLAYER CHARACTER / 3D POC')
            ->assertDontSee('data-player-character-svg-fallback', false)
            ->assertDontSee('data-renderer=', false);

        $content = $response->getContent();

        $this->assertSame(1, substr_count($content, 'account-player-character-stage--image'));
        $this->assertSame(1, substr_count($content, 'data-player-character-silhouette'));
        $this->assertSame(1, substr_count($content, 'data-player-character-modal'));
        $this->assertSame(0, substr_count($content, 'data-player-character-three'));
    }
}
