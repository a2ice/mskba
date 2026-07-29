<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum;
use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountPlayerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_player_can_open_and_fill_profile_with_multiple_positions_and_self_assessment(): void
    {
        $user = $this->player();

        $this->actingAs($user)
            ->get(route('account.participation-role', UserParticipationRoleEnum::PLAYER->value))
            ->assertOk()
            ->assertSee('Роль в проекте')
            ->assertSee('Характеристики игрока')
            ->assertSee('Самооценка игровых навыков')
            ->assertSee('Разыгрывающий')
            ->assertSee('Игровое мышление');

        $this->actingAs($user)
            ->get(route('account'))
            ->assertOk()
            ->assertSee(
                route('account.participation-role', UserParticipationRoleEnum::PLAYER->value),
                false,
            );

        $this->actingAs($user)
            ->patch(route('account.player-profile.update'), [
                'height_cm' => 191,
                'weight_kg' => 88,
                'body_type' => PlayerBodyTypeEnum::ATHLETIC->value,
                'positions' => [
                    PlayerPositionEnum::POINT_GUARD->value,
                    PlayerPositionEnum::SHOOTING_GUARD->value,
                ],
                'experience_started_year' => 2014,
                'comment' => 'Люблю быстрый баскетбол.',
                'self_assessment' => [
                    'stamina' => 8,
                    'speed' => 7,
                    'ball_handling' => 9,
                    'passing' => 8,
                    'close_range_shooting' => 8,
                    'mid_range_shooting' => 7,
                    'long_range_shooting' => 6,
                    'defense' => 6,
                    'rebounding' => 5,
                    'basketball_iq' => 9,
                ],
            ])
            ->assertRedirect(route('account.participation-role', UserParticipationRoleEnum::PLAYER->value))
            ->assertSessionHas('status', 'Профиль игрока обновлён.');

        $this->assertDatabaseHas('player_profiles', [
            'user_id' => $user->id,
            'height_cm' => 191,
            'weight_kg' => 88,
            'body_type' => PlayerBodyTypeEnum::ATHLETIC->value,
        ]);
        $this->assertDatabaseHas('player_profile_positions', [
            'position' => PlayerPositionEnum::POINT_GUARD->value,
        ]);
        $this->assertDatabaseHas('player_profile_positions', [
            'position' => PlayerPositionEnum::SHOOTING_GUARD->value,
        ]);
        $this->assertDatabaseHas('player_self_assessments', [
            'stamina' => 8,
            'close_range_shooting' => 8,
            'mid_range_shooting' => 7,
            'long_range_shooting' => 6,
            'basketball_iq' => 9,
        ]);

        $profilePage = $this->actingAs($user->fresh())
            ->get(route('account.participation-role', UserParticipationRoleEnum::PLAYER->value))
            ->assertOk();

        $this->assertMatchesRegularExpression(
            '/<option[^>]*value="88"[^>]*selected[^>]*>/',
            $profilePage->getContent(),
        );
    }

    public function test_updating_profile_replaces_positions_and_allows_partial_values(): void
    {
        $user = $this->player();

        $this->actingAs($user)->patch(route('account.player-profile.update'), [
            'positions' => [
                PlayerPositionEnum::POINT_GUARD->value,
                PlayerPositionEnum::CENTER->value,
            ],
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->patch(route('account.player-profile.update'), [
            'positions' => [PlayerPositionEnum::CENTER->value],
        ])->assertSessionHasNoErrors();

        $profile = $user->playerProfile()->firstOrFail();

        $this->assertSame(
            [PlayerPositionEnum::CENTER],
            $profile->positions()->pluck('position')->all(),
        );
    }

    public function test_non_player_cannot_update_player_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('account.player-profile.update'), ['height_cm' => 190])
            ->assertForbidden();

        $this->assertDatabaseMissing('player_profiles', ['user_id' => $user->id]);
    }

    public function test_save_and_close_returns_player_to_account_profile(): void
    {
        $user = $this->player();

        $this->actingAs($user)
            ->patch(route('account.player-profile.update'), [
                'height_cm' => 188,
                'redirect_to' => 'account',
            ])
            ->assertRedirect(route('account'))
            ->assertSessionHas('status', 'Профиль игрока обновлён.');
    }

    public function test_player_profile_rejects_invalid_physical_and_skill_values(): void
    {
        $user = $this->player();

        $this->actingAs($user)
            ->patch(route('account.player-profile.update'), [
                'height_cm' => 300,
                'weight_kg' => 141,
                'experience_started_year' => now()->year - 9,
                'positions' => ['forward'],
                'self_assessment' => ['stamina' => 11],
            ])
            ->assertSessionHasErrors([
                'height_cm',
                'weight_kg',
                'experience_started_year',
                'positions.0',
                'self_assessment.stamina',
            ]);
    }

    private function player(): User
    {
        $user = User::factory()->create();
        $user->participationRoles(false)->create([
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);

        return $user;
    }
}
