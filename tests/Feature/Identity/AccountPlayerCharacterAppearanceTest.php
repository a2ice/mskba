<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AccountPlayerCharacterAppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_can_save_image_character_payload_without_overwriting_other_extra_data(): void
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
                    'piercings' => ['left_ear', 'nose'],
                    'tattoos' => ['right_forearm', 'neck'],
                    'tattoo_note' => 'Чёрно-белая графика.',
                    'uniform_kit' => 'city_night',
                ],
            ])
            ->assertSessionHasNoErrors();

        $profile = $user->playerProfile()->firstOrFail();

        $this->assertTrue($profile->extra['legacy_flag']);
        $this->assertSame(3, $profile->extra['character']['version']);
        $this->assertSame('male', $profile->extra['character']['gender']);
        $this->assertSame('tan', $profile->extra['character']['skin_tone']);
        $this->assertSame('male_curls', $profile->extra['character']['hairstyle']);
        $this->assertSame('black', $profile->extra['character']['hair_color']);
        $this->assertSame('short_beard', $profile->extra['character']['facial_hair']);
        $this->assertSame(['left_ear', 'nose'], $profile->extra['character']['piercings']);
        $this->assertSame(['right_forearm', 'neck'], $profile->extra['character']['tattoos']);
        $this->assertSame('Чёрно-белая графика.', $profile->extra['character']['tattoo_note']);
        $this->assertSame('city_night', $profile->extra['character']['uniform_kit']);
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
                ],
            ])
            ->assertSessionHasErrors('character');
    }

    public function test_face_reference_is_stored_privately_and_mock_render_payload_is_queued(): void
    {
        Storage::fake('local');
        $user = $this->player(UserGenderEnum::MALE);

        $png = base64_encode(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAATUlEQVR4nO3PQQ0AIBDAsAP/nuGNAvZoFSzZOjNnyNiW9X0H4EoASgBKAEmAApAEKABJgAKQBCgASYACkAQoAEmAApAEKABJgAKQBCgAScAC7ykCYjAZmKQAAAAASUVORK5CYII=',
            true,
        ));

        $this->actingAs($user)
            ->patch(route('account.player-profile.update'), [
                'height_cm' => 185,
                'weight_kg' => 91,
                'character' => [
                    'skin_tone' => 'warm',
                    'hairstyle' => 'male_fade',
                    'hair_color' => 'dark_brown',
                    'facial_hair' => 'none',
                ],
                'character_face_photo_data' => 'data:image/png;base64,'.$png,
                'character_render_requested' => '1',
                'character_render_mode' => 'success',
            ])
            ->assertSessionHasNoErrors();

        $profile = $user->playerProfile()->firstOrFail();
        $path = $profile->extra['character']['face_photo_path'] ?? null;

        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame('mock', $profile->extra['character_render']['driver']);
        $this->assertSame('generating', $profile->extra['character_render']['status']);
        $this->assertSame('success', $profile->extra['character_render']['mode']);
        $this->assertSame(185, $profile->extra['character_render']['payload']['height_cm']);
        $this->assertTrue($profile->extra['character_render']['payload']['appearance']['has_face_reference']);
    }

    public function test_mock_error_mode_is_persisted_for_ui_testing(): void
    {
        $user = $this->player(UserGenderEnum::MALE);

        $this->actingAs($user)
            ->patch(route('account.player-profile.update'), [
                'character' => [
                    'skin_tone' => 'warm',
                    'hairstyle' => 'male_buzz',
                    'hair_color' => 'black',
                    'facial_hair' => 'none',
                ],
                'character_render_requested' => '1',
                'character_render_mode' => 'error',
            ])
            ->assertSessionHasNoErrors();

        $profile = $user->playerProfile()->firstOrFail();

        $this->assertSame('error', $profile->extra['character_render']['mode']);
        $this->assertStringContainsString('/images/player-character/mock/male-', $profile->extra['character_render']['result_url']);
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
