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
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Uniform\Application\Services\UniformLogoService;
use App\Modules\Uniform\Domain\Models\UniformDesign;
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
        config()->set('services.openai.image_model', 'gpt-image-2'); // Legacy server override.
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
            ->assertJsonPath('openai.model', 'gpt-image-2.5-sunburst')
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

    public function test_signed_runner_download_reads_custom_logo_from_personal_uniform_not_legacy_profile_collection(): void
    {
        $user = $this->player();
        $design = $this->design($user);
        $design->forceFill(['logo_source' => 'custom'])->save();
        app(UniformLogoService::class)->upload($design, UploadedFile::fake()->image('crest.png', 320, 320));
        $logo = $design->fresh()->customLogo()->firstOrFail();

        $generation = $this->logoGeneration($user, $logo->id, [
            'source' => 'mine',
            'design_id' => $design->id,
        ], 'custom');

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'integrations.github-openai.player-character.reference',
            now()->addMinutes(10),
            ['generation' => $generation->public_id, 'slot' => 'team_logo'],
        );

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
        // A generation bound to another user's design must not expose its file.
        $other = $this->player();
        $foreignGeneration = $this->logoGeneration($other, $logo->id, [
            'source' => 'mine',
            'design_id' => $design->id,
        ], 'custom');
        $foreignUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'integrations.github-openai.player-character.reference',
            now()->addMinutes(10),
            ['generation' => $foreignGeneration->public_id, 'slot' => 'team_logo'],
        );
        $this->get($foreignUrl)->assertNotFound();
    }

    public function test_signed_runner_download_reads_custom_logo_from_team_uniform(): void
    {
        $user = $this->player();
        $team = Team::query()->create(['name' => 'Команда теста', 'alias' => 'runner-crest']);
        $design = $this->design($user, $team->id);
        $design->forceFill(['logo_source' => 'custom'])->save();
        app(UniformLogoService::class)->upload($design, UploadedFile::fake()->image('team-crest.png', 320, 320));
        $logo = $design->fresh()->customLogo()->firstOrFail();

        $generation = $this->logoGeneration($user, $logo->id, [
            'source' => 'team',
            'design_id' => $design->id,
        ], 'custom', $team->id);

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'integrations.github-openai.player-character.reference',
            now()->addMinutes(10),
            ['generation' => $generation->public_id, 'slot' => 'team_logo'],
        );

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    public function test_signed_runner_download_resolves_logo_team_from_personal_uniform(): void
    {
        $user = $this->player();
        $team = Team::query()->create(['name' => 'Команда логотипа', 'alias' => 'uniform-logo-team']);
        $logoContents = UploadedFile::fake()->image('team.png', 320, 320)->get();
        Storage::disk('local')->put('tests/logos/team.png', $logoContents);
        $logo = $team->media()->create([
            'collection' => 'team_logo', 'disk' => 'local',
            'path' => 'tests/logos/team.png', 'mime' => 'image/png',
            'is_featured' => true,
        ]);
        $design = $this->design($user);
        $design->forceFill(['logo_source' => 'team', 'logo_team_id' => $team->id])->save();

        $generation = $this->logoGeneration($user, $logo->id, [
            'source' => 'mine',
            'design_id' => $design->id,
            'logo_team_id' => $team->id,
        ], 'team');
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'integrations.github-openai.player-character.reference',
            now()->addMinutes(10),
            ['generation' => $generation->public_id, 'slot' => 'team_logo'],
        );

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    private function design(User $user, ?int $teamId = null): UniformDesign
    {
        return UniformDesign::query()->create([
            'user_id' => $user->id,
            'team_id' => $teamId,
            'name' => 'Форма для GitHub runner',
            'primary_color' => '#111924',
            'secondary_color' => '#F58220',
            'sizes' => ['M'],
            'sleeve_style' => 'sleeveless',
            'logo_source' => 'none',
            'editor_version' => 1,
        ]);
    }

    /** @param array<string, mixed> $uniform */
    private function logoGeneration(User $user, int $logoId, array $uniform, string $logoSource, ?int $teamId = null): PlayerCharacterGeneration
    {
        return PlayerCharacterGeneration::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'provider' => 'github_openai',
            'status' => PlayerCharacterGenerationStatusEnum::PENDING,
            'reference_media_ids' => ['team_logo' => $logoId],
            'payload_snapshot' => [
                'uniform' => $uniform,
                'team' => [
                    'id' => $teamId,
                    'with_logo' => true,
                    'logo_source' => $logoSource,
                    'logo_mime' => 'image/webp',
                ],
            ],
            'expires_at' => now()->addMinutes(20),
        ]);
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
            $data['model'] = 'gpt-image-2.5-sunburst';
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
