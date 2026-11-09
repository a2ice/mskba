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
        // Legacy profile-level logo overrides cannot affect the selected uniform.
        unset($character['with_team_logo'], $character['generation_logo_mode'], $character['generation_custom_logo_id']);
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
                ->with(['logo', 'uniformDesign.customLogo', 'uniformDesign.logoTeam.logo'])
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
                ->whereNull('team_id')
                ->find((int) ($options['uniform_design_id'] ?? 0));
            if ($uniformDesign === null) {
                throw new PlayerCharacterFlowException(
                    'uniform_design_unavailable',
                    'Выберите сохранённую форму из раздела «Мои формы».',
                    422,
                );
            }
        }

        if ($playerProfile !== null) {
            $extra = is_array($playerProfile->extra) ? $playerProfile->extra : [];
            $persistedCharacter = is_array($extra['character'] ?? null) ? $extra['character'] : [];
            unset($persistedCharacter['with_team_logo'], $persistedCharacter['generation_logo_mode'], $persistedCharacter['generation_custom_logo_id']);
            $extra['character'] = array_merge($persistedCharacter, $character, [
                'generation_team_id' => $team?->id,
                'generation_uniform_source' => $uniformSource,
                'generation_uniform_design_id' => $uniformDesign?->id,
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

        // The team kit is stored on the team, not on the player's profile.
        // Per-player numbers always come from an accepted team membership.
        $teamUniform = $team?->uniformDesign;
        $teamMember = $team === null ? null : $team->memberships()
            ->whereIn('user_id', $user->identityIds())
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->withSportRole(TeamMemberTypeEnum::PLAYER)
            ->whereHas('contract', fn ($contract) => $contract
                ->where('status', ContractStatusEnum::ACTIVE->value))
            ->first();
        $teamNumber = $teamMember?->jersey_number;
        // Only a front-positioned crest is supplied for frontal AI avatars;
        // an explicitly back-only crest must not be hallucinated on the chest.
        $teamLogo = $teamUniform === null ? $team?->logo
            : (! (($teamUniform->logo_front_enabled ?? $teamUniform->logo_front_position !== 'none')
                || ($teamUniform->logo_shorts_enabled ?? false)) ? null : match ($teamUniform->logo_source) {
                'custom' => $teamUniform->customLogo,
                'team' => $teamUniform->logoTeam?->logo,
                default => null,
            });
        $selectedLogo = match ($uniformSource) {
            'mine' => ! ($uniformDesign->logo_front_enabled ?? $uniformDesign->logo_front_position !== 'none')
                && ! ($uniformDesign->logo_shorts_enabled ?? false)
                ? null
                : match ($uniformDesign->logo_source) {
                    'custom' => $uniformDesign->customLogo,
                    'team' => $uniformDesign->logoTeam?->logo,
                    default => null,
                },
            'team' => $teamLogo,
            default => null,
        };
        $logoSource = match ($uniformSource) {
            'mine' => (($uniformDesign->logo_front_enabled ?? $uniformDesign->logo_front_position !== 'none')
                || ($uniformDesign->logo_shorts_enabled ?? false)) ? $uniformDesign->logo_source : 'none',
            'team' => $teamUniform === null ? ($selectedLogo !== null ? 'team' : 'none')
                : ((($teamUniform->logo_front_enabled ?? $teamUniform->logo_front_position !== 'none')
                    || ($teamUniform->logo_shorts_enabled ?? false)) ? $teamUniform->logo_source : 'none'),
            default => 'none',
        };
        if ($logoSource !== 'none' && $selectedLogo === null) {
            throw new PlayerCharacterFlowException(
                'logo_unavailable',
                'Логотип выбранной формы недоступен. Проверьте его в мастерской.',
                422,
            );
        }
        $teamColors = is_array($team?->colors) ? $team->colors : [];
        $primaryTeamColor = data_get($teamColors, 'home_primary');
        $secondaryTeamColor = data_get($teamColors, 'home_secondary');
        $hasTeamKit = is_string($primaryTeamColor) && preg_match('/^#[0-9a-fA-F]{6}$/D', $primaryTeamColor) === 1;
        $uniform = match ($uniformSource) {
            'mine' => [
                'source' => 'mine',
                // Snapshot the selected design identity so signed runner downloads
                // can resolve its logo without consulting obsolete profile collections.
                'design_id' => $uniformDesign->id,
                'logo_team_id' => $uniformDesign->logo_team_id,
                'name' => $uniformDesign->name,
                'primary_color' => $uniformDesign->primary_color,
                'secondary_color' => $uniformDesign->secondary_color,
                'sleeve_style' => $uniformDesign->sleeve_style,
                'logo_source' => $logoSource,
                'jersey_number' => $uniformDesign->jersey_number,
                'number_enabled' => (bool) ($uniformDesign->number_enabled ?? true),
                'jersey_number_x' => $uniformDesign->jersey_number_x ?? 225,
                'jersey_number_y' => $uniformDesign->jersey_number_y ?? 394,
                'jersey_number_scale' => $uniformDesign->jersey_number_scale ?? 1,
                'jersey_back_number_x' => $uniformDesign->jersey_back_number_x ?? 735,
                'jersey_back_number_y' => $uniformDesign->jersey_back_number_y ?? 371,
                'jersey_back_number_scale' => $uniformDesign->jersey_back_number_scale ?? 1,
                'shorts_number_x' => $uniformDesign->shorts_number_x ?? 178,
                'shorts_number_y' => $uniformDesign->shorts_number_y ?? 755,
                'shorts_number_scale' => $uniformDesign->shorts_number_scale ?? 1,
                'number_color' => $uniformDesign->number_color,
                'number_outline' => (bool) $uniformDesign->number_outline,
                'number_outline_color' => $uniformDesign->number_outline_color,
                'logo_front_position' => $uniformDesign->logo_front_position,
                'logo_back_position' => $uniformDesign->logo_back_position,
                'logo_front_enabled' => (bool) ($uniformDesign->logo_front_enabled ?? true),
                'logo_back_enabled' => (bool) ($uniformDesign->logo_back_enabled ?? true),
                'logo_shorts_enabled' => (bool) ($uniformDesign->logo_shorts_enabled ?? false),
                'logo_front_x' => $uniformDesign->logo_front_x ?? 273,
                'logo_front_y' => $uniformDesign->logo_front_y ?? 256,
                'logo_front_scale' => $uniformDesign->logo_front_scale ?? 1,
                'logo_back_x' => $uniformDesign->logo_back_x ?? 735,
                'logo_back_y' => $uniformDesign->logo_back_y ?? 245,
                'logo_back_scale' => $uniformDesign->logo_back_scale ?? 1,
                'logo_shorts_x' => $uniformDesign->logo_shorts_x ?? 275,
                'logo_shorts_y' => $uniformDesign->logo_shorts_y ?? 686,
                'logo_shorts_scale' => $uniformDesign->logo_shorts_scale ?? 1,
            ],
            'team' => [
                'source' => $teamUniform !== null || $hasTeamKit ? 'team' : 'neutral',
                'design_id' => $teamUniform?->id,
                'logo_team_id' => $teamUniform?->logo_team_id,
                'team_name' => $team->name,
                'name' => $teamUniform?->name,
                'primary_color' => $teamUniform?->primary_color ?? ($hasTeamKit ? $primaryTeamColor : null),
                'secondary_color' => $teamUniform !== null
                    ? $teamUniform->secondary_color : ($hasTeamKit ? $secondaryTeamColor : null),
                'sleeve_style' => $teamUniform?->sleeve_style ?? 'sleeveless',
                'logo_source' => $logoSource,
                // The design controls whether numbers are printed, their color,
                // outline and placement. Only the roster controls their value.
                'jersey_number' => $teamUniform !== null && $teamNumber !== null
                    && $teamUniform->number_enabled ? $teamNumber : null,
                'number_enabled' => $teamUniform !== null && $teamNumber !== null
                    && (bool) $teamUniform->number_enabled,
                'number_color' => $teamUniform?->number_color,
                'number_outline' => (bool) ($teamUniform?->number_outline ?? false),
                'number_outline_color' => $teamUniform?->number_outline_color,
                'jersey_number_x' => $teamUniform?->jersey_number_x ?? 225,
                'jersey_number_y' => $teamUniform?->jersey_number_y ?? 394,
                'jersey_number_scale' => $teamUniform?->jersey_number_scale ?? 1,
                'jersey_back_number_x' => $teamUniform?->jersey_back_number_x ?? 735,
                'jersey_back_number_y' => $teamUniform?->jersey_back_number_y ?? 371,
                'jersey_back_number_scale' => $teamUniform?->jersey_back_number_scale ?? 1,
                'shorts_number_x' => $teamUniform?->shorts_number_x ?? 178,
                'shorts_number_y' => $teamUniform?->shorts_number_y ?? 755,
                'shorts_number_scale' => $teamUniform?->shorts_number_scale ?? 1,
                'logo_front_position' => $teamUniform?->logo_front_position ?? 'left',
                'logo_back_position' => $teamUniform?->logo_back_position ?? 'above',
                'logo_front_enabled' => (bool) ($teamUniform?->logo_front_enabled ?? true),
                'logo_back_enabled' => (bool) ($teamUniform?->logo_back_enabled ?? true),
                'logo_shorts_enabled' => (bool) ($teamUniform?->logo_shorts_enabled ?? false),
                'logo_front_x' => $teamUniform?->logo_front_x ?? 273,
                'logo_front_y' => $teamUniform?->logo_front_y ?? 256,
                'logo_front_scale' => $teamUniform?->logo_front_scale ?? 1,
                'logo_back_x' => $teamUniform?->logo_back_x ?? 735,
                'logo_back_y' => $teamUniform?->logo_back_y ?? 245,
                'logo_back_scale' => $teamUniform?->logo_back_scale ?? 1,
                'logo_shorts_x' => $teamUniform?->logo_shorts_x ?? 275,
                'logo_shorts_y' => $teamUniform?->logo_shorts_y ?? 686,
                'logo_shorts_scale' => $teamUniform?->logo_shorts_scale ?? 1,
            ],
            default => [
                'source' => 'neutral',
                'primary_color' => null,
                'secondary_color' => null,
                'sleeve_style' => 'sleeveless',
                'logo_source' => $logoSource,
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
                'logo_source' => $logoSource,
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
