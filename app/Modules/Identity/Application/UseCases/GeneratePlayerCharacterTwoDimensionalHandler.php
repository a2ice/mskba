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
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Pricing\Application\Services\PricingPriceResolver;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Uniform\Domain\Models\UniformDesign;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

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

        if ($this->ai instanceof AsynchronousPlayerCharacterAiGateway) {
            $activeGenerationExists = PlayerCharacterGeneration::query()
                ->whereIn('user_id', $user->identityIds())
                ->whereIn('status', [
                    PlayerCharacterGenerationStatusEnum::PENDING->value,
                    PlayerCharacterGenerationStatusEnum::PROCESSING->value,
                ])
                ->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->exists();

            if ($activeGenerationExists) {
                throw new PlayerCharacterFlowException(
                    'generation_in_progress',
                    'Другая генерация модели уже выполняется.',
                    409,
                );
            }
        }

        $playerProfile = $user->playerProfile()->first();
        $storedCharacter = (array) data_get($playerProfile?->extra, 'character', []);
        $requestedCharacter = is_array($options['character'] ?? null) ? $options['character'] : [];
        $character = array_merge($storedCharacter, $requestedCharacter);
        $character['attributes'] = PlayerCharacterAppearanceOptions::normalizeAttributes(
            (array) ($character['attributes'] ?? []),
        );

        // Backwards compatibility: older clients sent only generation_team_id.
        $uniformSource = (string) ($options['uniform_source'] ?? (
            ! empty($options['team_id']) ? 'team' : 'neutral'
        ));
        if (! in_array($uniformSource, ['neutral', 'team', 'mine'], true)) {
            throw new PlayerCharacterFlowException('uniform_source_invalid', 'Неизвестный тип формы.', 422);
        }

        $team = null;
        $teamId = $uniformSource === 'team' ? ($options['team_id'] ?? null) : null;
        if ($uniformSource === 'team' && ! $teamId) {
            throw new PlayerCharacterFlowException('uniform_team_required', 'Выберите команду.', 422);
        }
        if ($teamId !== null) {
            $team = Team::query()
                ->with('logo')
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

        $uniformDesign = null;
        if ($uniformSource === 'mine') {
            $uniformDesign = UniformDesign::query()
                ->with(['customLogo', 'logoTeam.logo'])
                ->whereIn('user_id', $user->identityIds())
                ->find((int) ($options['uniform_design_id'] ?? 0));
            if ($uniformDesign === null) {
                throw new PlayerCharacterFlowException(
                    'uniform_design_unavailable',
                    'Выберите сохранённую форму из раздела «Мои формы».',
                    422,
                );
            }
        }

        $logoMode = $uniformSource === 'mine' ? 'none' : (string) ($options['logo_mode'] ?? (
            ($options['with_team_logo'] ?? false) ? 'team' : 'none'
        ));
        if (! in_array($logoMode, ['none', 'team', 'custom'], true)) {
            throw new PlayerCharacterFlowException('logo_mode_invalid', 'Неизвестный вариант логотипа.', 422);
        }
        $withTeamLogo = $logoMode === 'team';
        if ($withTeamLogo && ($team === null || $team->logo === null)) {
            throw new PlayerCharacterFlowException('team_logo_unavailable', 'Выберите команду с логотипом.', 422);
        }
        $customLogo = null;
        if ($logoMode === 'custom') {
            $customLogo = Media::query()->where('mediable_type', User::class)
                ->whereIn('mediable_id', $user->identityIds())
                ->where('collection', 'player_generation_logos')
                ->find((int) ($options['custom_logo_id'] ?? 0));
            if ($customLogo === null) {
                throw new PlayerCharacterFlowException('custom_logo_unavailable', 'Выберите свой загруженный логотип.', 422);
            }
        }

        if ($playerProfile !== null) {
            $extra = is_array($playerProfile->extra) ? $playerProfile->extra : [];
            $persistedCharacter = is_array($extra['character'] ?? null) ? $extra['character'] : [];
            $extra['character'] = array_merge($persistedCharacter, $character, [
                'generation_team_id' => $team?->id,
                'generation_uniform_source' => $uniformSource,
                'generation_uniform_design_id' => $uniformDesign?->id,
                'with_team_logo' => $withTeamLogo,
                'generation_logo_mode' => $logoMode,
                'generation_custom_logo_id' => $customLogo?->id,
            ]);

            $profileUpdates = ['extra' => $extra];
            if (array_key_exists('height_cm', $options)) {
                $profileUpdates['height_cm'] = $options['height_cm'];
            }
            if (array_key_exists('weight_kg', $options)) {
                $profileUpdates['weight_kg'] = $options['weight_kg'];
            }
            if (array_key_exists('body_type', $options)) {
                $profileUpdates['body_type'] = $options['body_type'];
            }

            $playerProfile->forceFill($profileUpdates)->save();
            $playerProfile->refresh();
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

        // The avatar image is frontal: a back-only crest must never be
        // hallucinated on the chest merely because its image was attached.
        $selectedLogo = $uniformSource === 'mine'
            ? ($uniformDesign->logo_front_position === 'none' ? null : match ($uniformDesign->logo_source) {
                'custom' => $uniformDesign->customLogo,
                'team' => $uniformDesign->logoTeam?->logo,
                default => null,
            })
            : ($withTeamLogo ? $team?->logo : $customLogo);
        $teamColors = is_array($team?->colors) ? $team->colors : [];
        $primaryTeamColor = data_get($teamColors, 'home_primary');
        $secondaryTeamColor = data_get($teamColors, 'home_secondary');
        $hasTeamKit = is_string($primaryTeamColor) && preg_match('/^#[0-9a-fA-F]{6}$/D', $primaryTeamColor) === 1;
        $uniform = match ($uniformSource) {
            'mine' => [
                'source' => 'mine',
                'name' => $uniformDesign->name,
                'primary_color' => $uniformDesign->primary_color,
                'secondary_color' => $uniformDesign->secondary_color,
                'sleeve_style' => $uniformDesign->sleeve_style,
                'logo_source' => $uniformDesign->logo_source,
                'jersey_number' => $uniformDesign->jersey_number,
                'number_color' => $uniformDesign->number_color,
                'number_outline' => (bool) $uniformDesign->number_outline,
                'number_outline_color' => $uniformDesign->number_outline_color,
                'logo_front_position' => $uniformDesign->logo_front_position,
                'logo_back_position' => $uniformDesign->logo_back_position,
            ],
            'team' => [
                'source' => $hasTeamKit ? 'team' : 'neutral',
                'team_name' => $team->name,
                'primary_color' => $hasTeamKit ? $primaryTeamColor : null,
                'secondary_color' => $hasTeamKit ? $secondaryTeamColor : null,
                'sleeve_style' => 'sleeveless',
                'logo_source' => $logoMode,
            ],
            default => [
                'source' => 'neutral',
                'primary_color' => null,
                'secondary_color' => null,
                'sleeve_style' => 'sleeveless',
                'logo_source' => $logoMode,
            ],
        };
        $teamLogoPayload = null;
        if ($selectedLogo !== null) {
            $disk = Storage::disk($selectedLogo->disk);
            if (! $disk->exists($selectedLogo->path)) {
                throw new PlayerCharacterFlowException('logo_unavailable', 'Выбранный логотип временно недоступен.', 422);
            }
            $teamLogoPayload = ['contents' => $disk->get($selectedLogo->path),
                'mime' => $selectedLogo->mime ?: 'image/webp'];
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
            'uniform' => $uniform,
            'team' => ($team || $selectedLogo) ? [
                'id' => $team?->id, 'name' => $team?->name,
                'colors' => $hasTeamKit ? $teamColors : null,
                'with_logo' => $selectedLogo !== null,
                'logo_source' => $uniformSource === 'mine' ? $uniformDesign->logo_source : $logoMode,
                'logo_mime' => $selectedLogo?->mime,
            ] : null,
            'face_references' => $facePayload,
            'team_logo_reference' => $teamLogoPayload,
        ];

        $generation = null;

        if ($this->ai instanceof AsynchronousPlayerCharacterAiGateway) {
            $referenceMediaIds = $references
                ->mapWithKeys(fn ($media, string $slot): array => [$slot => (int) $media->id])
                ->all();

            if ($selectedLogo !== null) {
                $referenceMediaIds['team_logo'] = (int) $selectedLogo->id;
            }

            $payloadSnapshot = collect($generationPayload)
                ->except(['face_references', 'team_logo_reference'])
                ->all();
            $payloadSnapshot['price_minor'] = $priceMinor;

            $generation = PlayerCharacterGeneration::query()->create([
                'public_id' => (string) Str::uuid(),
                // References belong to the profile that initiated the request.
                // Wallet canonicalization must not change that media owner.
                'user_id' => $user->id,
                'provider' => 'github_openai',
                'status' => PlayerCharacterGenerationStatusEnum::PENDING,
                'reference_media_ids' => $referenceMediaIds,
                'payload_snapshot' => $payloadSnapshot,
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
        } catch (Throwable $exception) {
            // Never strand an unexpected provider/billing failure as "pending".
            // The billable gateway owns any compensating wallet refund.
            if ($generation !== null) {
                $generation->forceFill([
                    'status' => PlayerCharacterGenerationStatusEnum::FAILED,
                    'error_code' => 'generation_unexpected_error',
                    'error_message' => 'Генерация завершилась ошибкой. Попробуйте позже.',
                    'failed_at' => now(),
                ])->save();
            }

            throw $exception;
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
