<?php

namespace Tests\Feature\Identity;

use App\Modules\Ai\Application\Contracts\PlayerCharacterAiGateway;
use App\Modules\Ai\Application\Dto\FaceReferenceValidationResult;
use App\Modules\Ai\Application\Dto\GeneratedPlayerCharacterImage;
use App\Modules\Ai\Domain\Exceptions\AiServiceException;
use App\Modules\Ai\Infrastructure\Gateways\NullPlayerCharacterAiGateway;
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

    public function test_failed_ai_replacement_keeps_previous_confirmed_reference(): void
    {
        Storage::fake('local');
        $user = $this->player();

        $this->bindGateway();
        $this->uploadReference($user, 'front');

        $profile = $user->profile()->firstOrFail();
        $reference = $profile->media()
            ->where('collection', PlayerCharacterFaceReferenceOptions::collectionForSlot('front'))
            ->firstOrFail();

        $this->bindGateway(faceValid: false);

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('account.player-profile.update'), [
                '_method' => 'PATCH',
                'mutation' => 'face_reference',
                'face_reference_slot' => 'front',
                'face_reference' => UploadedFile::fake()->image('wrong-angle.jpg', 1200, 900),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'face_reference_invalid');

        $this->assertDatabaseHas('media', [
            'id' => $reference->id,
            'path' => $reference->path,
            'source_reference' => PlayerCharacterFaceReferenceOptions::AI_VALIDATED_REFERENCE,
        ]);
        Storage::disk('local')->assertExists($reference->path);
        $this->assertSame(
            1,
            $profile->media()
                ->where('collection', PlayerCharacterFaceReferenceOptions::collectionForSlot('front'))
                ->count(),
        );
    }

    public function test_confirmed_face_reference_can_be_previewed_only_by_its_owner(): void
    {
        Storage::fake('local');
        $user = $this->player();
        $other = $this->player();

        $this->bindGateway();
        $this->uploadReference($user, 'front');

        $this->actingAs($user)
            ->get(route('account.player-character.face-reference', ['slot' => 'front']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs($other)
            ->get(route('account.player-character.face-reference', ['slot' => 'front']))
            ->assertNotFound();
    }

    public function test_generation_checks_face_references_before_balance_and_provider(): void
    {
        $gateway = $this->bindGateway();
        $user = $this->player();

        $this->actingAs($user)
            ->patchJson(route('account.player-profile.update'), [
                'mutation' => 'generate_2d',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'face_references_missing')
            ->assertJsonPath('price_minor', 10000);

        $this->assertFalse($gateway->generationCalled);
    }

    public function test_generation_checks_balance_after_required_face_references(): void
    {
        Storage::fake('local');
        $gateway = $this->bindGateway();
        $user = $this->player();

        $this->uploadReference($user, 'front');
        $this->uploadReference($user, 'left');

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

    public function test_pending_face_references_are_validated_once_then_saved_and_used_for_generation(): void
    {
        Storage::fake('local');
        $user = $this->player();
        $this->credit($user, 10_000);
        $gateway = $this->bindGateway();

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('account.player-profile.update'), [
                '_method' => 'PATCH',
                'mutation' => 'generate_2d',
                'generation_face_references' => [
                    'front' => UploadedFile::fake()->image('front.jpg', 1200, 900),
                    'right' => UploadedFile::fake()->image('right.jpg', 1200, 900),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'generated')
            ->assertJsonPath('face_previews.front', route('account.player-character.face-reference', ['slot' => 'front']).'?v=1')
            ->assertJsonPath('face_previews.right', route('account.player-character.face-reference', ['slot' => 'right']).'?v=2');

        $this->assertSame(1, $gateway->validationCalls);
        $this->assertSame(['front', 'right'], $gateway->lastValidatedSlots);
        $this->assertTrue($gateway->generationCalled);
        $this->assertArrayHasKey('front', $gateway->lastPayload['face_references']);
        $this->assertArrayHasKey('right', $gateway->lastPayload['face_references']);

        $this->assertDatabaseHas('media', [
            'collection' => PlayerCharacterFaceReferenceOptions::collectionForSlot('front'),
            'source_reference' => PlayerCharacterFaceReferenceOptions::AI_VALIDATED_REFERENCE,
        ]);
        $this->assertDatabaseHas('media', [
            'collection' => PlayerCharacterFaceReferenceOptions::collectionForSlot('right'),
            'source_reference' => PlayerCharacterFaceReferenceOptions::AI_VALIDATED_REFERENCE,
        ]);
    }

    public function test_pending_face_validation_is_not_called_when_generation_balance_is_insufficient(): void
    {
        Storage::fake('local');
        $user = $this->player();
        $gateway = $this->bindGateway();

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('account.player-profile.update'), [
                '_method' => 'PATCH',
                'mutation' => 'generate_2d',
                'generation_face_references' => [
                    'front' => UploadedFile::fake()->image('front.jpg', 1200, 900),
                    'left' => UploadedFile::fake()->image('left.jpg', 1200, 900),
                ],
            ])
            ->assertStatus(402)
            ->assertJsonPath('code', 'insufficient_balance');

        $this->assertSame(0, $gateway->validationCalls);
        $this->assertFalse($gateway->generationCalled);
        $this->assertDatabaseCount('media', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_invalid_pending_face_batch_saves_nothing_and_does_not_generate(): void
    {
        Storage::fake('local');
        $user = $this->player();
        $this->credit($user, 10_000);
        $gateway = $this->bindGateway(faceValid: false);

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('account.player-profile.update'), [
                '_method' => 'PATCH',
                'mutation' => 'generate_2d',
                'generation_face_references' => [
                    'front' => UploadedFile::fake()->image('front.jpg', 1200, 900),
                    'left' => UploadedFile::fake()->image('left.jpg', 1200, 900),
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'face_reference_invalid');

        $this->assertSame(1, $gateway->validationCalls);
        $this->assertFalse($gateway->generationCalled);
        $this->assertDatabaseCount('media', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_generation_uses_current_unsaved_character_settings(): void
    {
        Storage::fake('local');
        $user = $this->player();
        $this->credit($user, 10_000);

        $gateway = $this->bindGateway();
        $this->uploadReference($user, 'front');
        $this->uploadReference($user, 'right');

        $this->actingAs($user)
            ->patchJson(route('account.player-profile.update'), [
                'mutation' => 'generate_2d',
                'height_cm' => 198,
                'weight_kg' => 92,
                'body_type' => 'athletic',
                'character' => [
                    'skin_tone' => 'tan',
                    'hairstyle' => 'male_fade',
                    'hair_color' => 'black',
                    'facial_hair' => 'none',
                    'uniform_kit' => 'mskba_home',
                    'shoes' => 'black',
                    'attributes' => ['elbow_left', 'wristband_right', 'knee_right'],
                ],
            ])
            ->assertOk();

        $this->assertTrue($gateway->generationCalled);
        $this->assertSame(198, $gateway->lastPayload['height_cm']);
        $this->assertSame(92, $gateway->lastPayload['weight_kg']);
        $this->assertSame('athletic', $gateway->lastPayload['body_type']);
        $this->assertSame('black', $gateway->lastPayload['appearance']['shoes']);
        $this->assertSame(['elbow_left', 'wristband_right', 'knee_right'], $gateway->lastPayload['appearance']['attributes']);
    }

    public function test_confirmed_face_references_are_reused_for_repeat_generation(): void
    {
        Storage::fake('local');
        $user = $this->player();
        $this->credit($user, 20_000);

        $gateway = $this->bindGateway();
        $this->uploadReference($user, 'front');
        $this->uploadReference($user, 'left');

        $this->actingAs($user)
            ->patchJson(route('account.player-profile.update'), [
                'mutation' => 'generate_2d',
            ])
            ->assertOk();

        $this->actingAs($user)
            ->patchJson(route('account.player-profile.update'), [
                'mutation' => 'generate_2d',
            ])
            ->assertOk();

        $this->assertTrue($gateway->generationCalled);
        $this->assertSame(2, $gateway->validationCalls);
        $this->assertArrayHasKey('front', $gateway->lastPayload['face_references']);
        $this->assertArrayHasKey('left', $gateway->lastPayload['face_references']);
    }

    public function test_generation_returns_stable_not_configured_error_after_preflight(): void
    {
        Storage::fake('local');
        $user = $this->player();
        $this->credit($user, 10_000);

        $this->bindGateway();
        $this->uploadReference($user, 'front');
        $this->uploadReference($user, 'left');

        $this->app->instance(PlayerCharacterAiGateway::class, new NullPlayerCharacterAiGateway);

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

            public int $validationCalls = 0;

            /** @var array<int, string> */
            public array $lastValidatedSlots = [];

            /** @var array<string, mixed>|null */
            public ?array $lastPayload = null;

            public function __construct(
                private readonly bool $faceValid,
                private readonly ?AiServiceException $generationFailure,
            ) {}

            public function validateFaceReferences(array $references): array
            {
                $this->validationCalls++;
                $this->lastValidatedSlots = array_keys($references);

                $result = [];
                foreach ($references as $slot => $_reference) {
                    $result[$slot] = new FaceReferenceValidationResult(
                        valid: $this->faceValid,
                        detectedSlot: $this->faceValid ? $slot : ($slot === 'left' ? 'right' : 'left'),
                        reason: $this->faceValid ? null : 'Загружено фото в другом ракурсе.',
                    );
                }

                return $result;
            }

            public function generatePlayerCharacter(array $payload): GeneratedPlayerCharacterImage
            {
                $this->generationCalled = true;
                $this->lastPayload = $payload;

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
