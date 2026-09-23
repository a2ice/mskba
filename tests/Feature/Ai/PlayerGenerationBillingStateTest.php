<?php

namespace Tests\Feature\Ai;

use App\Modules\Ai\Application\Contracts\PlayerCharacterAiGateway;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use App\Modules\Finance\Application\UseCases\CreditWalletHandler;
use App\Modules\Finance\Application\UseCases\EnsureWalletHandler;
use App\Modules\Finance\Domain\Enums\WalletBalanceTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOperationTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Models\Wallet;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class PlayerGenerationBillingStateTest extends TestCase
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
        $this->app->forgetInstance(PlayerCharacterAiGateway::class);
    }

    public function test_generation_debits_wallet_once_and_rejects_a_second_active_generation(): void
    {
        Http::fake([
            'https://api.github.test/repos/a2ice/mskba/actions/workflows/openai-player-generate.yml/dispatches' => Http::response(null, 204),
        ]);

        $user = $this->player();
        $wallet = $this->wallet($user);
        $this->credit($wallet, $user, WalletBalanceTypeEnum::BONUS, 10_000, 'initial-bonus');

        $first = $this->startGeneration($user)
            ->assertAccepted()
            ->assertJsonPath('status', 'pending');

        $generationId = (string) $first->json('generation_id');
        $wallet->refresh();
        $this->assertSame(0, $wallet->totalBalanceMinor());
        $this->assertDatabaseHas('wallet_operations', [
            'type' => WalletOperationTypeEnum::INTERNAL_SERVICE_PAYMENT->value,
            'reference_type' => 'player_character_generation',
            'reference_key' => $generationId,
        ]);

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('account.player-profile.update'), [
                '_method' => 'PATCH',
                'mutation' => 'generate_2d',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'generation_in_progress');

        $wallet->refresh();
        $this->assertSame(0, $wallet->totalBalanceMinor());
        $this->assertSame(1, PlayerCharacterGeneration::query()->where('status', 'pending')->count());
        Http::assertSentCount(1);
    }

    public function test_failed_generation_refunds_original_bonus_and_real_split_only_once(): void
    {
        Http::fake([
            'https://api.github.test/repos/a2ice/mskba/actions/workflows/openai-player-generate.yml/dispatches' => Http::response(null, 204),
        ]);

        $user = $this->player();
        $wallet = $this->wallet($user);
        $this->credit($wallet, $user, WalletBalanceTypeEnum::BONUS, 4_000, 'split-bonus');
        $this->credit($wallet, $user, WalletBalanceTypeEnum::REAL, 6_000, 'split-real');

        $response = $this->startGeneration($user)->assertAccepted();
        $generationId = (string) $response->json('generation_id');
        $wallet->refresh();
        $this->assertSame(0, $wallet->totalBalanceMinor());

        $callbackUrl = route('integrations.github-openai.player-character.callback', [
            'generation' => $generationId,
        ]);

        $this->postSignedFailure($callbackUrl, $generationId)->assertOk();
        $this->postSignedFailure($callbackUrl, $generationId)->assertOk();

        $wallet->refresh();
        $this->assertSame(4_000, (int) $wallet->bonus_balance_minor);
        $this->assertSame(6_000, (int) $wallet->real_balance_minor);
        $this->assertSame(2, $wallet->ledgerEntries()
            ->whereHas('operation', fn ($query) => $query
                ->where('type', WalletOperationTypeEnum::REFUND->value))
            ->count());
    }

    public function test_dispatch_failure_refunds_the_charge(): void
    {
        Http::fake([
            'https://api.github.test/repos/a2ice/mskba/actions/workflows/openai-player-generate.yml/dispatches' => Http::response([], 500),
        ]);

        $user = $this->player();
        $wallet = $this->wallet($user);
        $this->credit($wallet, $user, WalletBalanceTypeEnum::BONUS, 10_000, 'dispatch-failure-bonus');

        $this->startGeneration($user)
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_connection_failed');

        $wallet->refresh();
        $this->assertSame(10_000, (int) $wallet->bonus_balance_minor);
        $this->assertSame(0, (int) $wallet->real_balance_minor);
        $this->assertDatabaseHas('player_character_generations', [
            'user_id' => $user->id,
            'status' => 'failed',
        ]);
    }

    private function startGeneration(User $user): TestResponse
    {
        return $this->actingAs($user)
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
                ],
            ]);
    }

    private function postSignedFailure(string $url, string $generationId): TestResponse
    {
        $timestamp = (string) now()->timestamp;
        $contentsHash = hash('sha256', '');
        $data = [
            'status' => 'failed',
            'provider_run_id' => '12345',
            'error_code' => 'generation_failed',
            'error_message' => 'OpenAI generation failed on GitHub runner.',
        ];
        $metadataHash = hash('sha256', implode("\n", [
            $data['provider_run_id'],
            $data['error_code'],
            $data['error_message'],
            '',
        ]));
        $signature = hash_hmac(
            'sha256',
            implode("\n", [$timestamp, $generationId, 'failed', $contentsHash, $metadataHash]),
            'callback-test-secret',
        );

        return $this->withHeaders([
            'X-MSKBA-Timestamp' => $timestamp,
            'X-MSKBA-Content-SHA256' => $contentsHash,
            'X-MSKBA-Metadata-SHA256' => $metadataHash,
            'X-MSKBA-Signature' => $signature,
        ])->post($url, $data);
    }

    private function wallet(User $user): Wallet
    {
        return app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $user->id);
    }

    private function credit(
        Wallet $wallet,
        User $user,
        WalletBalanceTypeEnum $balanceType,
        int $amountMinor,
        string $key,
    ): void {
        app(CreditWalletHandler::class)->handle(
            wallet: $wallet,
            balanceType: $balanceType,
            amountMinor: $amountMinor,
            operationType: $balanceType === WalletBalanceTypeEnum::REAL
                ? WalletOperationTypeEnum::TOP_UP
                : WalletOperationTypeEnum::BONUS_GRANT,
            idempotencyKey: $key.'-'.$user->id,
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
