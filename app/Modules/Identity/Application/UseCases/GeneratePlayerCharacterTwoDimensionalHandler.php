<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Ai\Application\Contracts\AsynchronousPlayerCharacterAiGateway;
use App\Modules\Ai\Application\Contracts\PlayerCharacterAiGateway;
use App\Modules\Ai\Domain\Enums\PlayerCharacterGenerationStatusEnum;
use App\Modules\Ai\Domain\Exceptions\AiServiceException;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Finance\Application\Services\WalletOwnerResolver;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletTypeEnum;
use App\Modules\Finance\Domain\Models\Wallet;
use App\Modules\Identity\Domain\Exceptions\PlayerCharacterFlowException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Support\PlayerCharacterAppearanceOptions;
use App\Modules\Identity\Domain\Support\PlayerCharacterFaceReferenceOptions;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Pricing\Application\Services\PricingPriceResolver;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class GeneratePlayerCharacterTwoDimensionalHandler
{
    public const SERVICE_CODE = 'avatar_generation';

    public function __construct(
        private PricingPriceResolver $prices,
        private WalletOwnerResolver $owners,
        private PlayerCharacterAiGateway $ai,
        private WebpImageNormalizer $normalizer,
        private PersistValidatedPlayerCharacterFaceReferencesHandler $facePersister,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, string>  $pendingFaceReferences
     * @return array{
     *   status: string,
     *   price_minor: int,
     *   available_minor: int,
     *   image_contents?: string,
     *   image_mime?: string,
     *   generation_id?: string,
     *   validated_face_media_ids: array<string, int>
     * }
     */
    public function handle(User $user, array $options = [], array $pendingFaceReferences = []): array
    {
        $price = $this->prices->resolve(self::SERVICE_CODE);

        if ($price === null) {
            throw new PlayerCharacterFlowException(
                'pricing_unavailable',
                'Цена генерации временно недоступна.',
                503,
            );
        }

        $priceMinor = (int) $price->amount_minor;
        $profile = $user->profile;

        if ($profile === null) {
            throw new PlayerCharacterFlowException(
                'profile_missing',
                'Сначала заполните базовый профиль пользователя.',
                422,
            );
        }

        $references = $this->confirmedReferences($user);
        $effectiveSlots = array_fill_keys($references->keys()->all(), true);

        foreach (array_keys($pendingFaceReferences) as $slot) {
            PlayerCharacterFaceReferenceOptions::collectionForSlot($slot);
            $effectiveSlots[$slot] = true;
        }

        if (! isset($effectiveSlots['front']) || (! isset($effectiveSlots['left']) && ! isset($effectiveSlots['right']))) {
            throw new PlayerCharacterFlowException(
                'face_references_missing',
                'Для генерации загрузите анфас и фото слева или справа.',
                422,
                [
                    'price_minor' => $priceMinor,
                    'currency' => 'RUB',
                ],
            );
        }

        $canonicalUserId = $this->owners->canonicalOwnerId(WalletOwnerTypeEnum::USER, $user->id);
        $wallet = Wallet::query()
            ->where('owner_type', WalletOwnerTypeEnum::USER->value)
            ->where('owner_id', $canonicalUserId)
            ->where('type', WalletTypeEnum::MAIN->value)
            ->where('currency', 'RUB')
            ->first();

        $availableMinor = $wallet?->totalBalanceMinor() ?? 0;

        if ($availableMinor < $priceMinor) {
            throw new PlayerCharacterFlowException(
                'insufficient_balance',
                'Недостаточно средств для генерации модели.',
                402,
                [
                    'price_minor' => $priceMinor,
                    'available_minor' => $availableMinor,
                    'currency' => 'RUB',
                ],
            );
        }

        $validatedFaceMediaIds = [];

        if ($pendingFaceReferences !== []) {
            $normalized = [];
            $validationPayload = [];

            foreach ($pendingFaceReferences as $slot => $contents) {
                PlayerCharacterFaceReferenceOptions::collectionForSlot($slot);

                $image = $this->normalizer->normalize(
                    $contents,
                    PlayerCharacterFaceReferenceOptions::MAX_OUTPUT_DIMENSION,
                );

                $normalized[$slot] = $image;
                $validationPayload[$slot] = [
                    'contents' => $image['contents'],
                    'mime' => $image['mime'],
                ];
            }

            $validations = $this->ai->validateFaceReferences($validationPayload);

            foreach ($normalized as $slot => $_image) {
                $validation = $validations[$slot] ?? null;

                if ($validation === null || ! $validation->valid) {
                    $expectedLabel = PlayerCharacterFaceReferenceOptions::labels()[$slot] ?? $slot;

                    throw new PlayerCharacterFlowException(
                        'face_reference_invalid',
                        $validation?->reason ?: 'Фото не соответствует ракурсу «'.$expectedLabel.'».',
                        422,
                        [
                            'expected_slot' => $slot,
                            'detected_slot' => $validation?->detectedSlot,
                            'price_minor' => $priceMinor,
                            'currency' => 'RUB',
                        ],
                    );
                }
            }

            $persisted = $this->facePersister->handle($profile, $normalized);

            foreach ($persisted as $slot => $result) {
                $validatedFaceMediaIds[$slot] = $result['media']->id;
            }

            $references = $this->confirmedReferences($user);
        }

        $facePayload = [];
        foreach (PlayerCharacterFaceReferenceOptions::SLOTS as $slot) {
            $reference = $references->get($slot);

            if ($reference === null) {
                continue;
            }

            $contents = Storage::disk($reference->disk)->get($reference->path);
            $facePayload[$slot] = [
                'contents' => $contents,
                'mime' => $reference->mime,
            ];
        }

        $playerProfile = $user->playerProfile()->first();
        $storedCharacter = (array) data_get($playerProfile?->extra, 'character', []);
        $requestedCharacter = is_array($options['character'] ?? null) ? $options['character'] : [];
        $character = array_merge($storedCharacter, $requestedCharacter);
        $character['attributes'] = PlayerCharacterAppearanceOptions::normalizeAttributes(
            (array) ($character['attributes'] ?? []),
        );

        $team = null;
        $teamId = $options['team_id'] ?? null;
        if ($teamId !== null) {
            $team = Team::query()
                ->whereKey((int) $teamId)
                ->whereNull('temporary_for_event_id')
                ->whereHas('memberships', fn ($memberships) => $memberships
                    ->whereIn('user_id', $user->identityIds())
                    ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                    ->withSportRole(TeamMemberTypeEnum::PLAYER)
                    ->whereHas('contract', fn ($contract) => $contract
                        ->where('status', ContractStatusEnum::ACTIVE->value)))
                ->first(['id', 'name', 'colors']);

            if ($team === null) {
                throw new PlayerCharacterFlowException(
                    'team_unavailable',
                    'Выбранная команда недоступна для генерации.',
                    422,
                );
            }
        }

        $generationPayload = [
            'user_id' => $canonicalUserId,
            'gender' => $user->profile?->gender?->value,
            'height_cm' => array_key_exists('height_cm', $options)
                ? $options['height_cm']
                : $playerProfile?->height_cm,
            'weight_kg' => array_key_exists('weight_kg', $options)
                ? $options['weight_kg']
                : $playerProfile?->weight_kg,
            'body_type' => array_key_exists('body_type', $options)
                ? $options['body_type']
                : ($playerProfile?->body_type?->value ?? $playerProfile?->body_type),
            'appearance' => $character,
            'team' => $team ? [
                'id' => $team->id,
                'name' => $team->name,
                'colors' => $team->colors,
            ] : null,
            'face_references' => $facePayload,
        ];

        $generation = null;

        if ($this->ai instanceof AsynchronousPlayerCharacterAiGateway) {
            $generation = PlayerCharacterGeneration::query()->create([
                'public_id' => (string) Str::uuid(),
                // References belong to the profile that initiated the request.
                // Wallet canonicalization must not change that media owner.
                'user_id' => $user->id,
                'provider' => 'github_openai',
                'status' => PlayerCharacterGenerationStatusEnum::PENDING,
                'reference_media_ids' => $references
                    ->mapWithKeys(fn ($media, string $slot): array => [$slot => (int) $media->id])
                    ->all(),
                'payload_snapshot' => collect($generationPayload)->except('face_references')->all(),
                'expires_at' => now()->addSeconds(
                    max(300, (int) config('services.github_openai.generation_timeout_seconds', 1200)),
                ),
            ]);

            $generationPayload['generation_id'] = $generation->public_id;
        }

        try {
            $image = $this->ai->generatePlayerCharacter($generationPayload);
        } catch (AiServiceException $exception) {
            if ($generation !== null) {
                $generation->forceFill([
                    'status' => PlayerCharacterGenerationStatusEnum::FAILED,
                    'error_code' => $exception->errorCode,
                    'error_message' => $exception->getMessage(),
                    'failed_at' => now(),
                ])->save();
            }

            if ($validatedFaceMediaIds === []) {
                throw $exception;
            }

            throw new AiServiceException(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
                ['validated_face_media_ids' => $validatedFaceMediaIds],
            );
        }

        if ($image->isPending() && $generation !== null) {
            return [
                'status' => PlayerCharacterGenerationStatusEnum::PENDING->value,
                'price_minor' => $priceMinor,
                'available_minor' => $availableMinor,
                'generation_id' => $generation->public_id,
                'validated_face_media_ids' => $validatedFaceMediaIds,
            ];
        }

        if ($image->contents === '' || ! str_starts_with($image->mime, 'image/')) {
            throw new PlayerCharacterFlowException(
                'generation_failed',
                'Не удалось сгенерировать модель игрока.',
                502,
            );
        }

        return [
            'status' => PlayerCharacterGenerationStatusEnum::COMPLETED->value,
            'price_minor' => $priceMinor,
            'available_minor' => $availableMinor,
            'image_contents' => $image->contents,
            'image_mime' => $image->mime,
            'validated_face_media_ids' => $validatedFaceMediaIds,
        ];
    }

    private function confirmedReferences(User $user): Collection
    {
        return $user->profile?->media()
            ->whereIn('collection', PlayerCharacterFaceReferenceOptions::collections())
            ->where('source_reference', PlayerCharacterFaceReferenceOptions::AI_VALIDATED_REFERENCE)
            ->latest('id')
            ->get()
            ->keyBy(fn ($media) => PlayerCharacterFaceReferenceOptions::slotForCollection($media->collection))
            ?? collect();
    }
}
