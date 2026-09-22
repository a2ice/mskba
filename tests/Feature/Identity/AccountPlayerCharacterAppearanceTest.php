<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserGenderEnum;
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
        $user = $this->player(UserGenderEnum::MALE);
        $user->playerProfile()->create([
            'extra' => [
                'legacy_flag' => true,
            ],
        ]);

        $this->actingAs($user)
            ->patch(route('account.player-profile.update'), [
                'character' => [
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
        $this->assertSame(5, $profile->extra['character']['version']);
        $this->assertSame('male', $profile->extra['character']['gender']);
        $this->assertSame('tan', $profile->extra['character']['skin_tone']);
        $this->assertSame('male_curls', $profile->extra['character']['hairstyle']);
        $this->assertSame('black', $profile->extra['character']['hair_color']);
        $this->assertSame('short_beard', $profile->extra['character']['facial_hair']);
        $this->assertSame('city_night', $profile->extra['character']['uniform_kit']);
        $this->assertSame('white', $profile->extra['character']['shoes']);
        $this->assertSame([], $profile->extra['character']['attributes']);
    }

    public function test_player_can_save_shoes_and_optional_attributes(): void
    {
        $user = $this->player(UserGenderEnum::MALE);

        $this->actingAs($user)
            ->patch(route('account.player-profile.update'), [
                'character' => [
                    'skin_tone' => 'warm',
                    'hairstyle' => 'male_fade',
                    'hair_color' => 'dark_brown',
                    'facial_hair' => 'none',
                    'uniform_kit' => 'mskba_home',
                    'shoes' => 'black',
                    'attributes' => ['elbow_left', 'elbow_right', 'wristband_left', 'knee_right', 'headband'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $character = $user->playerProfile()->firstOrFail()->extra['character'];

        $this->assertSame('black', $character['shoes']);
        $this->assertSame(
            ['elbow_left', 'elbow_right', 'wristband_left', 'knee_right', 'headband'],
            $character['attributes'],
        );
    }

    public function test_legacy_combined_equipment_attributes_are_expanded_to_sides(): void
    {
        $this->assertSame(
            [
                'elbow_left',
                'elbow_right',
                'wristband_left',
                'wristband_right',
                'knee_left',
                'knee_right',
                'headband',
            ],
            \App\Modules\Identity\Domain\Support\PlayerCharacterAppearanceOptions::normalizeAttributes([
                'elbow_both',
                'wristbands',
                'knee_pads',
                'headband',
            ]),
        );
    }

    public function test_character_appearance_uses_profile_gender_for_compatibility(): void
    {
        $user = $this->player(UserGenderEnum::FEMALE);

        $this->actingAs($user)
            ->patch(route('account.player-profile.update'), [
                'character' => [
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

    public function test_character_gender_cannot_be_overridden_from_player_character_form(): void
    {
        $user = $this->player(UserGenderEnum::MALE);

        $this->actingAs($user)
            ->patch(route('account.player-profile.update'), [
                'character' => [
                    'gender' => 'female',
                    'skin_tone' => 'warm',
                    'hairstyle' => 'male_fade',
                    'hair_color' => 'dark_brown',
                    'facial_hair' => 'none',
                    'uniform_kit' => 'mskba_home',
                ],
            ])
            ->assertSessionHasErrors('character');
    }

    private function player(UserGenderEnum $gender): User
    {
        $user = User::factory()->create();
        $user->profile()->create([
            'gender' => $gender,
        ]);
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
