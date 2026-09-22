<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Ai\Application\Contracts\PlayerCharacterAiGateway;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Finance\Application\Services\WalletOwnerResolver;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Finance\Domain\Enums\WalletTypeEnum;
use App\Modules\Finance\Domain\Models\Wallet;
use App\Modules\Identity\Domain\Exceptions\PlayerCharacterFlowException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Support\PlayerCharacterAppearanceOptions;
use App\Modules\Identity\Domain\Support\PlayerCharacterFaceReferenceOptions;
use App\Modules\Pricing\Application\Services\PricingPriceResolver;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Support\Facades\Storage;

final readonly class GeneratePlayerCharacterTwoDimensionalHandler
{
    public const SERVICE_CODE = 'avatar_generation';

    public function __construct(
        private PricingPriceResolver $prices,
        private WalletOwnerResolver $owners,
        private PlayerCharacterAiGateway $ai,
    ) {}

    /**
     * @return array{price_minor: int, available_minor: int, image_contents: string, image_mime: string}
     */
    public function handle(User $user, array $options = []): array
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
        $references = $profile?->media()
            ->whereIn('collection', PlayerCharacterFaceReferenceOptions::collections())
            ->where('source_reference', PlayerCharacterFaceReferenceOptions::AI_VALIDATED_REFERENCE)
            ->latest('id')
            ->get()
            ->keyBy(fn ($media) => PlayerCharacterFaceReferenceOptions::slotForCollection($media->collection))
            ?? collect();

        if (! $references->has('front') || (! $references->has('left') && ! $references->has('right'))) {
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

        $image = $this->ai->generatePlayerCharacter([
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
        ]);

        if ($image->contents === '' || ! str_starts_with($image->mime, 'image/')) {
            throw new PlayerCharacterFlowException(
                'generation_failed',
                'Не удалось сгенерировать модель игрока.',
                502,
            );
        }

        return [
            'price_minor' => $priceMinor,
            'available_minor' => $availableMinor,
            'image_contents' => $image->contents,
            'image_mime' => $image->mime,
        ];
    }
}
