<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountPlayerCharacterStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_character_stage_exposes_renderer_agnostic_profile_contract(): void
    {
        $user = User::factory()->create();
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
            ->assertSee('data-player-character-stage', false)
            ->assertSee('data-player-character-plot', false)
            ->assertSee('data-height="191"', false)
            ->assertSee('data-weight="88"', false)
            ->assertSee('data-body-type="athletic"', false)
            ->assertSee('data-skin-tone="default"', false)
            ->assertSee('data-hairstyle="default"', false)
            ->assertSee('data-player-character-input="height"', false)
            ->assertSee('data-player-character-input="weight"', false)
            ->assertSee('data-player-character-input="body-type"', false);

        $this->assertSame(1, substr_count($response->getContent(), 'account-player-character-layout'));
        $this->assertSame(1, substr_count($response->getContent(), 'account-player-character-stage__plot'));
    }
}
