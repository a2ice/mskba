<?php

namespace Tests\Feature\Identity;

use App\Modules\Ai\Application\Contracts\PlayerCharacterAiGateway;
use App\Modules\Ai\Application\Dto\FaceReferenceValidationResult;
use App\Modules\Ai\Application\Dto\GeneratedPlayerCharacterImage;
use App\Modules\Ai\Domain\Exceptions\AiServiceException;
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
use App\Modules\Identity\Domain\Support\PlayerCharacterFaceReferenceOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PlayerCharacterAiFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_face_reference_is_not_persisted_when_ai_is_not_configured(): void
    {
        Storage::fake('local');
        $user = $this->player();

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('account.player-profile.update'), [
                '_method' => 'PATCH',
                'mutation' => 'face_reference',
                'face_reference_slot' => 'front',
                'face_reference' => UploadedFile::fake()->image('front.jpg', 1200, 900),
            ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_not_configured');

        $this->assertDatabaseMissing('media', [
            'collection' => PlayerCharacterFaceReferenceOptions::collectionForSlot('front'),
        ]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_face_reference_wrong_angle_is_not_persisted(): void
    {
        Storage::fake('local');
        $this->bindGateway(faceValid: false);
        $user = $this->player();

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('account.player-profile.update'), [
                '_method' => 'PATCH',
                'mutation' => 'face_reference',
                'face_reference_slot' => 'front',
                'face_reference' => UploadedFile::fake()->image('profile.jpg', 1200, 900),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'face_reference_invalid');

        $this->assertDatabaseMissing('media', [
            'collection' => PlayerCharacterFaceReferenceOptions::collectionForSlot('front'),
        ]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_generation_checks_balance_before_calling_ai(): void
    {
        $gateway = $this->bindGateway();
        $user = $this->player();

        $this->actingAs($user)
            ->patchJson(route('account.player-profile.update'), [
                'mutation' => 'generate_2d',
            ])
            ->assertStatus(402)
            ->assertJsonPath('code', 'insufficient_balance')
            ->assertJsonPath('price_minor', 10000)
            ->assertJsonPath('available_minor', 0);

        $this->assertFalse($gateway->generationCalled);
    }

    public function test_generation_requires_ai_validated_face_references_after_balance_check(): void
    {
        $gateway = $this->bindGateway();
        $user = $this->player();
        $this->credit($user, 10_000);

        $this->actingAs($user)
            ->patchJson(route('account.player-profile.update'), [
                'mutation' => 'generate_2d',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'face_references_missing');

        $this->assertFalse($gateway->generationCalled);
    }

    public function test_generation_returns_stable_not_configured_error_after_preflight(): void
    {
        Storage::fake('local');
        $user = $this->player();
        $this->credit($user, 10_000);

        $this->bindGateway();
        $this->uploadReference($user, 'front');
        $this->uploadReference($user, 'left');

        $this->app->forgetInstance(PlayerCharacterAiGateway::class);

        $this->actingAs($user)
            ->patchJson(route('account.player-profile.update'), [
                'mutation' => 'generate_2d',
            ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_not_configured');
    }

    public function test_generation_maps_provider_connection_failure_to_stable_error_code(): void
    {
        Storage::fake('local');
        $user = $this->player();
        $this->credit($user, 10_000);

        $this->bindGateway();
        $this->uploadReference($user, 'front');
        $this->uploadReference($user, 'right');

        $this->bindGateway(generationFailure: AiServiceException::connectionFailed());

        $this->actingAs($user)
            ->patchJson(route('account.player-profile.update'), [
                'mutation' => 'generate_2d',
            ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_connection_failed');
    }

    private function bindGateway(
        bool $faceValid = true,
        ?AiServiceException $generationFailure = null,
    ): object {
        $gateway = new class($faceValid, $generationFailure) implements PlayerCharacterAiGateway
        {
            public bool $generationCalled = false;

            public function __construct(
                private readonly bool $faceValid,
                private readonly ?AiServiceException $generationFailure,
            ) {}

            public function validateFaceReference(
                string $expectedSlot,
                string $imageContents,
                string $mime,
            ): FaceReferenceValidationResult {
                return new FaceReferenceValidationResult(
                    valid: $this->faceValid,
                    detectedSlot: $this->faceValid ? $expectedSlot : 'left',
                    reason: $this->faceValid ? null : 'Загружено фото в другом ракурсе.',
                );
            }

            public function generatePlayerCharacter(array $payload): GeneratedPlayerCharacterImage
            {
                $this->generationCalled = true;

                if ($this->generationFailure !== null) {
                    throw $this->generationFailure;
                }

                return new GeneratedPlayerCharacterImage('generated-image', 'image/webp');
            }
        };

        $this->app->instance(PlayerCharacterAiGateway::class, $gateway);

        return $gateway;
    }

    private function uploadReference(User $user, string $slot): void
    {
        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('account.player-profile.update'), [
                '_method' => 'PATCH',
                'mutation' => 'face_reference',
                'face_reference_slot' => $slot,
                'face_reference' => UploadedFile::fake()->image($slot.'.jpg', 1200, 900),
            ])
            ->assertOk()
            ->assertJsonPath('status', 'stored');
    }

    private function credit(User $user, int $amountMinor): void
    {
        $wallet = app(EnsureWalletHandler::class)->handle(WalletOwnerTypeEnum::USER, $user->id);

        app(CreditWalletHandler::class)->handle(
            wallet: $wallet,
            balanceType: WalletBalanceTypeEnum::BONUS,
            amountMinor: $amountMinor,
            operationType: WalletOperationTypeEnum::BONUS_GRANT,
            idempotencyKey: 'test-player-character-ai-'.$user->id.'-'.$amountMinor,
            performedByUserId: $user->id,
        );
    }

    private function player(): User
    {
        $user = User::factory()->create([
            'system_role' => UserSystemRoleEnum::USER,
        ]);
        $user->profile()->create([
            'gender' => UserGenderEnum::MALE,
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
