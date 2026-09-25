<?php

namespace Tests\Feature\Ai;

use App\Modules\Ai\Domain\Enums\PlayerCharacterGenerationStatusEnum;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlayerCharacterGenerationUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_select_any_completed_generation_as_primary(): void
    {
        $user = $this->player();
        $older = $this->completedGeneration($user, now()->subMinutes(5));
        $newer = $this->completedGeneration($user, now()->subMinute());

        $this->actingAs($user)
            ->getJson(route('account.player-character.generations.show', 'latest'))
            ->assertOk()
            ->assertJsonPath('generation_id', $newer->public_id);

        $this->actingAs($user)
            ->patchJson(route('account.player-profile.update'), [
                'mutation' => 'generation_primary',
                'generation_id' => $older->public_id,
            ])
            ->assertOk()
            ->assertJsonPath('generation_id', $older->public_id);

        $this->assertSame(
            $older->public_id,
            data_get($user->playerProfile()->firstOrFail()->fresh()->extra, 'character.primary_generation_id'),
        );

        $this->actingAs($user)
            ->getJson(route('account.player-character.generations.show', 'latest'))
            ->assertOk()
            ->assertJsonPath('generation_id', $older->public_id);

        $history = $this->actingAs($user)
            ->patchJson(route('account.player-profile.update'), [
                'mutation' => 'generation_history',
            ])
            ->assertOk()
            ->assertJsonPath('primary_generation_id', $older->public_id);

        $items = collect($history->json('generations'));
        $this->assertTrue((bool) $items->firstWhere('generation_id', $older->public_id)['is_primary']);
        $this->assertFalse((bool) $items->firstWhere('generation_id', $newer->public_id)['is_primary']);
    }

    public function test_generation_preferences_return_saved_visual_options(): void
    {
        $user = $this->player();
        $profile = $user->playerProfile()->firstOrFail();
        $profile->forceFill([
            'height_cm' => 196,
            'weight_kg' => 94,
            'body_type' => 'athletic',
            'extra' => [
                'character' => [
                    'shoes' => 'white',
                    'attributes' => ['headband'],
                    'generation_team_id' => 321,
                    'with_team_logo' => true,
                ],
            ],
        ])->save();

        $this->actingAs($user)
            ->patchJson(route('account.player-profile.update'), [
                'mutation' => 'generation_preferences',
            ])
            ->assertOk()
            ->assertJsonPath('height_cm', 196)
            ->assertJsonPath('weight_kg', 94)
            ->assertJsonPath('body_type', 'athletic')
            ->assertJsonPath('generation_team_id', 321)
            ->assertJsonPath('with_team_logo', true)
            ->assertJsonPath('character.shoes', 'white')
            ->assertJsonPath('character.attributes.0', 'headband');
    }

    public function test_generation_start_persists_current_player_and_visual_settings(): void
    {
        $user = $this->player();

        $this->actingAs($user)
            ->patchJson(route('account.player-profile.update'), [
                'mutation' => 'generate_2d',
                'height_cm' => 199,
                'weight_kg' => 97,
                'body_type' => 'athletic',
                'generation_with_team_logo' => false,
                'character' => [
                    'skin_tone' => 'tan',
                    'hairstyle' => 'male_fade',
                    'hair_color' => 'black',
                    'facial_hair' => 'short_beard',
                    'uniform_kit' => 'city_night',
                    'shoes' => 'black',
                    'attributes' => ['headband', 'knee_left', 'knee_right'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'face_references_missing');

        $profile = $user->playerProfile()->firstOrFail()->fresh();
        $this->assertSame(199, $profile->height_cm);
        $this->assertSame('97.0', (string) $profile->weight_kg);
        $this->assertSame('athletic', $profile->body_type?->value);
        $this->assertSame('city_night', data_get($profile->extra, 'character.uniform_kit'));
        $this->assertSame('black', data_get($profile->extra, 'character.shoes'));
        $this->assertSame(
            ['headband', 'knee_left', 'knee_right'],
            data_get($profile->extra, 'character.attributes'),
        );
        $this->assertFalse((bool) data_get($profile->extra, 'character.with_team_logo'));
    }

    public function test_generation_quote_uses_current_pricing_catalog(): void
    {
        $user = $this->player();

        $this->actingAs($user)
            ->patchJson(route('account.player-profile.update'), [
                'mutation' => 'generation_quote',
            ])
            ->assertOk()
            ->assertJsonPath('price_minor', 10_000)
            ->assertJsonPath('currency', 'RUB');
    }

    private function completedGeneration(User $user, mixed $completedAt): PlayerCharacterGeneration
    {
        $publicId = (string) Str::uuid();

        return PlayerCharacterGeneration::query()->create([
            'public_id' => $publicId,
            'user_id' => $user->id,
            'provider' => 'github_openai',
            'status' => PlayerCharacterGenerationStatusEnum::COMPLETED,
            'reference_media_ids' => [],
            'payload_snapshot' => ['price_minor' => 10_000],
            'result_disk' => 'local',
            'result_path' => 'player-character-generations/'.$publicId.'/result.png',
            'result_mime' => 'image/png',
            'result_size' => 128,
            'completed_at' => $completedAt,
        ]);
    }

    private function player(): User
    {
        $user = User::factory()->create(['system_role' => UserSystemRoleEnum::USER]);
        $user->profile()->create(['gender' => UserGenderEnum::MALE]);
        $user->participationRoles(false)->create([
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
        $user->playerProfile()->create([
            'height_cm' => 186,
            'weight_kg' => 88,
            'body_type' => 'athletic',
            'extra' => ['character' => []],
        ]);

        return $user;
    }
}
