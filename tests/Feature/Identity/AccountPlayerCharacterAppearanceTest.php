<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountPlayerCharacterAppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_can_save_character_appearance_without_overwriting_other_extra_data(): void
    {
        $user = $this->player();
        $user->playerProfile()->create([
            'extra' => [
                'legacy_flag' => true,
            ],
        ]);

        $this->actingAs($user)
            ->patch(route('account.player-profile.update'), [
                'character' => [
                    'gender' => 'male',
                    'skin_tone' => 'tan',
                    'hairstyle' => 'male_curls',
                    'hair_color' => 'black',
                    'facial_hair' => 'short_beard',
                    'uniform_kit' => 'city_night',
                ],
            ])
            ->assertSessionHasNoErrors();

        $profile = $user->playerProfile()->firstOrFail();

        $this->assertTrue($profile->extra['legacy_flag']);
        $this->assertSame(1, $profile->extra['character']['version']);
        $this->assertSame('male', $profile->extra['character']['gender']);
        $this->assertSame('tan', $profile->extra['character']['skin_tone']);
        $this->assertSame('male_curls', $profile->extra['character']['hairstyle']);
        $this->assertSame('black', $profile->extra['character']['hair_color']);
        $this->assertSame('short_beard', $profile->extra['character']['facial_hair']);
        $this->assertSame('city_night', $profile->extra['character']['uniform_kit']);
    }

    public function test_character_appearance_rejects_gender_incompatible_hair_and_facial_hair(): void
    {
        $user = $this->player();

        $this->actingAs($user)
            ->patch(route('account.player-profile.update'), [
                'character' => [
                    'gender' => 'female',
                    'skin_tone' => 'warm',
                    'hairstyle' => 'male_fade',
                    'hair_color' => 'dark_brown',
                    'facial_hair' => 'full_beard',
                    'uniform_kit' => 'mskba_home',
                ],
            ])
            ->assertSessionHasErrors([
                'character.hairstyle',
                'character.facial_hair',
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
