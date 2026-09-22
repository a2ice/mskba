<?php

namespace Tests\Feature\Identity;

use App\Modules\Ai\Application\Contracts\PlayerCharacterAiGateway;
use App\Modules\Ai\Application\Dto\FaceReferenceValidationResult;
use App\Modules\Ai\Application\Dto\GeneratedPlayerCharacterImage;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Support\PlayerCharacterFaceReferenceOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AccountPlayerCharacterRendererTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_player_defaults_to_2d_and_backend_rejects_3d(): void
    {
        $user = $this->player(UserSystemRoleEnum::USER);

        $this->actingAs($user)
            ->get(route('account.participation-role', UserParticipationRoleEnum::PLAYER->value))
            ->assertOk()
            ->assertSee('data-render-mode="2d"', false)
            ->assertSee('data-player-character-two', false)
            ->assertSee('body-male.png', false)
            ->assertSee('data-player-character-render-mode="3d"', false);

        $this->actingAs($user)
            ->patchJson(route('account.player-profile.update'), [
                'mutation' => 'render_mode',
                'render_mode' => '3d',
            ])
            ->assertForbidden()
            ->assertJsonPath('render_mode', '2d')
            ->assertJsonPath('message', '3D-модель пока доступна только администраторам.');

        $this->assertNull($user->playerProfile()->first());
    }

    public function test_admin_can_enable_3d_and_regular_profile_save_preserves_renderer_mode(): void
    {
        $user = $this->player(UserSystemRoleEnum::ADMIN);

        $this->actingAs($user)
            ->patchJson(route('account.player-profile.update'), [
                'mutation' => 'render_mode',
                'render_mode' => '3d',
            ])
            ->assertOk()
            ->assertJsonPath('render_mode', '3d');

        $this->assertSame(
            '3d',
            data_get($user->playerProfile()->firstOrFail()->extra, 'character.render_mode'),
        );

        $this->actingAs($user)
            ->patch(route('account.player-profile.update'), [
                'height_cm' => 192,
                'character' => [
                    'skin_tone' => 'warm',
                    'hairstyle' => 'male_fade',
                    'hair_color' => 'dark_brown',
                    'facial_hair' => 'none',
                    'uniform_kit' => 'mskba_home',
                ],
            ])
            ->assertSessionHasNoErrors();

        $profile = $user->playerProfile()->firstOrFail()->refresh();
        $this->assertSame('3d', data_get($profile->extra, 'character.render_mode'));
        $this->assertSame(192, $profile->height_cm);
    }

    public function test_superadmin_can_enable_3d(): void
    {
        $user = $this->player(UserSystemRoleEnum::SUPERADMIN);

        $this->actingAs($user)
            ->patchJson(route('account.player-profile.update'), [
                'mutation' => 'render_mode',
                'render_mode' => '3d',
            ])
            ->assertOk()
            ->assertJsonPath('render_mode', '3d');
    }

    public function test_female_player_can_store_manual_chest_volume(): void
    {
        $user = $this->player(UserSystemRoleEnum::USER, UserGenderEnum::FEMALE);

        $this->actingAs($user)
            ->patch(route('account.player-profile.update'), [
                'character' => [
                    'skin_tone' => 'warm',
                    'hairstyle' => 'female_ponytail',
                    'hair_color' => 'dark_brown',
                    'facial_hair' => 'none',
                    'uniform_kit' => 'mskba_home',
                    'chest_volume' => 'large',
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'large',
            data_get($user->playerProfile()->firstOrFail()->extra, 'character.chest_volume'),
        );

        $this->actingAs($user->fresh())
            ->get(route('account.participation-role', UserParticipationRoleEnum::PLAYER->value))
            ->assertOk()
            ->assertSee('Объём груди')
            ->assertSee('data-player-character-input="chest-volume"', false)
            ->assertSee('body-female.png', false);
    }

    public function test_male_profile_does_not_accept_chest_volume_as_body_inference(): void
    {
        $user = $this->player(UserSystemRoleEnum::USER, UserGenderEnum::MALE);

        $this->actingAs($user)
            ->patch(route('account.player-profile.update'), [
                'character' => [
                    'skin_tone' => 'warm',
                    'hairstyle' => 'male_fade',
                    'hair_color' => 'dark_brown',
                    'facial_hair' => 'none',
                    'uniform_kit' => 'mskba_home',
                    'chest_volume' => 'full',
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(
            data_get($user->playerProfile()->firstOrFail()->extra, 'character.chest_volume'),
        );
    }

    public function test_face_reference_is_normalized_to_private_webp_and_invalid_replacement_keeps_previous_file(): void
    {
        Storage::fake('local');
        $this->app->instance(PlayerCharacterAiGateway::class, new class implements PlayerCharacterAiGateway
        {
            public function validateFaceReferences(array $references): array
            {
                return collect($references)
                    ->mapWithKeys(fn (array $_reference, string $slot): array => [
                        $slot => new FaceReferenceValidationResult(true, $slot),
                    ])
                    ->all();
            }

            public function generatePlayerCharacter(array $payload): GeneratedPlayerCharacterImage
            {
                return new GeneratedPlayerCharacterImage('test', 'image/webp');
            }
        });

        $user = $this->player(UserSystemRoleEnum::USER);

        $response = $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('account.player-profile.update'), [
                '_method' => 'PATCH',
                'mutation' => 'face_reference',
                'face_reference_slot' => 'front',
                'face_reference' => UploadedFile::fake()->image('front.jpg', 1600, 1200),
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('slot', 'front')
            ->assertJsonPath('status', 'stored');

        $profile = $user->profile()->firstOrFail();
        $reference = $profile->media()
            ->where('collection', PlayerCharacterFaceReferenceOptions::collectionForSlot('front'))
            ->firstOrFail();

        $this->assertSame('local', $reference->disk);
        $this->assertSame('image/webp', $reference->mime);
        Storage::disk('local')->assertExists($reference->path);

        $storedContents = Storage::disk('local')->get($reference->path);
        $dimensions = getimagesizefromstring($storedContents);
        $this->assertIsArray($dimensions);
        $this->assertLessThanOrEqual(512, max($dimensions[0], $dimensions[1]));

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('account.player-profile.update'), [
                '_method' => 'PATCH',
                'mutation' => 'face_reference',
                'face_reference_slot' => 'front',
                'face_reference' => UploadedFile::fake()->create('not-an-image.txt', 1, 'text/plain'),
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('media', [
            'id' => $reference->id,
            'disk' => 'local',
            'path' => $reference->path,
        ]);
        Storage::disk('local')->assertExists($reference->path);
    }

    private function player(
        UserSystemRoleEnum $systemRole,
        UserGenderEnum $gender = UserGenderEnum::MALE,
    ): User {
        $user = User::factory()->create([
            'system_role' => $systemRole,
        ]);
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
