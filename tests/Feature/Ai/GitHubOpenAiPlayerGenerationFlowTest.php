<?php

namespace Tests\Feature\Ai;

use App\Modules\Ai\Application\Contracts\PlayerCharacterAiGateway;
use App\Modules\Ai\Domain\Enums\PlayerCharacterGenerationStatusEnum;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use App\Modules\Finance\Application\UseCases\CreditWalletHandler;
use App\Modules\Finance\Application\UseCases\EnsureWalletHandler;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class GitHubOpenAiPlayerGenerationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('services.player_character_ai.provider', 'github_openai');
        config()->set('services.github_openai', [
            'api_url' => 'https://api.github.test',
            'repository' => 'a2ice/mskba',
            'workflow' => 'openai-player-generate.yml',
            'ref' => 'main',
            'token' => 'github-test-token',
            'callback_secret' => 'callback-test-secret',
            'manifest_ttl_minutes' => 20,
            'callback_tolerance_seconds' => 300,
            'connect_timeout_seconds' => 1,
            'dispatch_timeout_seconds' => 5,
            'generation_timeout_seconds' => 1200,
            'image_quality' => 'high',
        ]);
        config()->set('services.openai.image_model', 'gpt-image-2');
        config()->set('services.openai.image_size', '1024x1536');
        config()->set('services.openai.image_quality', 'high');
        $this->app->forgetInstance(PlayerCharacterAiGateway::class);
    }

    public function test_generation_dispatch_manifest_callback_and_private_result_flow(): void
    {
        Http::fake([
            'https://api.github.test/repos/a2ice/mskba/actions/workflows/openai-player-generate.yml/dispatches' => Http::response(null, 204),
        ]);

        $user = $this->player();
        $this->credit($user, 10_000);

        $response = $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('account.player-profile.update'), [
                '_method' => 'PATCH',
                'mutation' => 'generate_2d',
                'height_cm' => 185,
                'weight_kg' => 90,
                'body_type' => 'athletic',
                'generation_face_references' => [
                    'front' => UploadedFile::fake()->image('front.jpg', 1200, 900),
                    'left' => UploadedFile::fake()->image('left.jpg', 1200, 900),
                    'right' => UploadedFile::fake()->image('right.jpg', 1200, 900),
                ],
            ])
            ->assertAccepted()
            ->assertJsonPath('status', 'pending');

        $generationId = (string) $response->json('generation_id');
        $generation = PlayerCharacterGeneration::query()->where('public_id', $generationId)->firstOrFail();

        $this->assertSame('github_openai', $generation->provider);
        $this->assertEqualsCanonicalizing(
            ['front', 'left', 'right'],
            array_keys($generation->reference_media_ids),
        );

        $manifestUrl = null;
        Http::assertSent(function (Request $request) use (&$manifestUrl): bool {
            $manifestUrl = data_get($request->data(), 'inputs.manifest_url');

            return is_string($manifestUrl) && $manifestUrl !== '';
        });

        $manifest = $this->getJson((string) $manifestUrl)
            ->assertOk()
            ->assertJsonPath('generation_id', $generationId)
            ->assertJsonPath('openai.model', 'gpt-image-2')
            ->assertJsonCount(3, 'references');

        $frontUrl = (string) $manifest->json('references.front');
        $frontResponse = $this->get($frontUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
        $this->assertStringContainsString('no-store', (string) $frontResponse->headers->get('Cache-Control'));

        $callbackUrl = (string) $manifest->json('callback_url');
        $this->postSignedCallback($callbackUrl, $generationId, 'processing')
            ->assertOk();

        $this->actingAs($user)
            ->getJson(route('account.player-character.generations.show', $generationId))
            ->assertOk()
            ->assertJsonPath('status', 'processing');

        $png = $this->transparentPng();
        $this->postSignedCallback($callbackUrl, $generationId, 'completed', $png)
            ->assertOk();
        $this->postSignedCallback($callbackUrl, $generationId, 'completed', $png)
            ->assertOk();

        $status = $this->actingAs($user)
            ->getJson(route('account.player-character.generations.show', $generationId))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $imageResponse = $this->actingAs($user)
            ->get((string) $status->json('image_url'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('no-store', (string) $imageResponse->headers->get('Cache-Control'));

        $other = $this->player();
        $this->actingAs($other)
            ->getJson(route('account.player-character.generations.show', $generationId))
            ->assertNotFound();
    }

    public function test_expired_non_terminal_generation_is_reported_as_failed(): void
    {
        $user = $this->player();
        $generation = PlayerCharacterGeneration::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'provider' => 'github_openai',
            'status' => PlayerCharacterGenerationStatusEnum::PENDING,
            'reference_media_ids' => [],
            'payload_snapshot' => [],
            'expires_at' => now()->subSecond(),
        ]);

        $this->actingAs($user)
            ->getJson(route('account.player-character.generations.show', $generation->public_id))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('code', 'generation_timeout');

        $this->assertDatabaseHas('player_character_generations', [
            'id' => $generation->id,
            'status' => PlayerCharacterGenerationStatusEnum::FAILED->value,
            'error_code' => 'generation_timeout',
        ]);
    }

    private function postSignedCallback(
        string $url,
        string $generationId,
        string $status,
        string $contents = '',
    ): TestResponse {
        $timestamp = (string) now()->timestamp;
        $hash = hash('sha256', $contents);
        $data = ['status' => $status, 'provider_run_id' => '12345'];
        if ($contents !== '') {
            $data['image'] = UploadedFile::fake()->createWithContent('player.png', $contents);
            $data['model'] = 'gpt-image-2';
        }

        $metadataHash = hash('sha256', implode("\n", [
            $data['provider_run_id'],
            '',
            '',
            $data['model'] ?? '',
        ]));
        $signature = hash_hmac(
            'sha256',
            implode("\n", [$timestamp, $generationId, $status, $hash, $metadataHash]),
            'callback-test-secret',
        );

        return $this->withHeaders([
            'X-MSKBA-Timestamp' => $timestamp,
            'X-MSKBA-Content-SHA256' => $hash,
            'X-MSKBA-Metadata-SHA256' => $metadataHash,
            'X-MSKBA-Signature' => $signature,
        ])->post($url, $data);
    }

    private function transparentPng(): string
    {
        $image = imagecreatetruecolor(64, 96);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        $this->assertIsString($contents);

        return $contents;
    }

    private function credit(User $user, int $amountMinor): void
    {
        $wallet = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $user->id);

        app(CreditWalletHandler::class)->handle(
            wallet: $wallet,
            balanceType: WalletBalanceTypeEnum::BONUS,
            amountMinor: $amountMinor,
            operationType: WalletOperationTypeEnum::BONUS_GRANT,
            idempotencyKey: 'test-github-openai-'.$user->id.'-'.$amountMinor,
            performedByUserId: $user->id,
        );
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

        return $user;
    }
}
