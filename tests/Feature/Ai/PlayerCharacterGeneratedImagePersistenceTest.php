<?php

namespace Tests\Feature\Ai;

use App\Modules\Ai\Domain\Enums\PlayerCharacterGenerationStatusEnum;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlayerCharacterGeneratedImagePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_completed_generation_can_be_restored_after_reload(): void
    {
        $user = User::factory()->create();
        $generation = $this->completedGeneration($user, now()->subMinute());

        $response = $this->actingAs($user)
            ->getJson(route('account.player-character.generations.show', 'latest'))
            ->assertOk()
            ->assertJsonPath('generation_id', $generation->public_id)
            ->assertJsonPath('status', PlayerCharacterGenerationStatusEnum::COMPLETED->value);

        $this->assertStringContainsString(
            route('account.player-character.generations.image', $generation->public_id),
            (string) $response->json('image_url'),
        );
    }

    public function test_newer_failed_generation_does_not_replace_previous_completed_image(): void
    {
        $user = User::factory()->create();
        $completed = $this->completedGeneration($user, now()->subMinutes(2));

        PlayerCharacterGeneration::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'provider' => 'github_openai',
            'status' => PlayerCharacterGenerationStatusEnum::FAILED,
            'reference_media_ids' => [],
            'payload_snapshot' => [],
            'error_code' => 'provider_failed',
            'error_message' => 'Provider failed.',
            'failed_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson(route('account.player-character.generations.show', 'latest'))
            ->assertOk()
            ->assertJsonPath('generation_id', $completed->public_id)
            ->assertJsonPath('status', PlayerCharacterGenerationStatusEnum::COMPLETED->value);
    }

    public function test_latest_completed_generation_is_scoped_to_current_identity(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->completedGeneration($owner, now()->subMinute());

        $this->actingAs($other)
            ->getJson(route('account.player-character.generations.show', 'latest'))
            ->assertNotFound();
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
            'payload_snapshot' => [],
            'result_disk' => 'local',
            'result_path' => 'player-character-generations/'.$publicId.'/result.png',
            'result_mime' => 'image/png',
            'result_size' => 128,
            'completed_at' => $completedAt,
        ]);
    }
}
