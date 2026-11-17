<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Ai\Domain\Enums\PlayerCharacterGenerationStatusEnum;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Support\PlayerCharacterFaceReferenceOptions;

final class PlayerCharacterInitialRenderService
{
    /**
     * Private face references are rendered with the HTML, eliminating the
     * placeholder-to-photo swap while retaining the existing authorized route.
     *
     * @return array<string, array{id: int, url: string, verified: bool}>
     */
    public function faceReferences(User $user): array
    {
        $profile = $user->profile;
        if ($profile === null) {
            return [];
        }

        $references = $profile->media()
            ->whereIn('collection', PlayerCharacterFaceReferenceOptions::collections())
            ->where('source_reference', PlayerCharacterFaceReferenceOptions::AI_VALIDATED_REFERENCE)
            ->latest('id')
            ->get()
            ->unique('collection');

        $result = [];
        foreach ($references as $reference) {
            $slot = PlayerCharacterFaceReferenceOptions::slotForCollection($reference->collection);
            if ($slot === null) {
                continue;
            }

            $verified = PlayerCharacterGeneration::query()
                ->whereIn('user_id', $user->identityIds())
                ->where('status', PlayerCharacterGenerationStatusEnum::COMPLETED->value)
                ->whereJsonContains('reference_media_ids->'.$slot, (int) $reference->id)
                ->exists();

            $result[$slot] = [
                'id' => (int) $reference->id,
                'url' => route('account.player-character.face-reference', ['slot' => $slot]).'?v='.$reference->id,
                'verified' => $verified,
            ];
        }

        return $result;
    }

    /** @return array{generation_id: string, image_url: string}|null */
    public function initialAvatar(User $user): ?array
    {
        $preferredId = (string) data_get(
            $user->playerProfile?->extra,
            'character.primary_generation_id',
            '',
        );

        $completed = PlayerCharacterGeneration::query()
            ->whereIn('user_id', $user->identityIds())
            ->where('status', PlayerCharacterGenerationStatusEnum::COMPLETED->value)
            ->whereNotNull('result_disk')
            ->whereNotNull('result_path')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $generation = $preferredId !== ''
            ? (clone $completed)->where('public_id', $preferredId)->first()
            : null;

        $generation ??= $completed->first();
        if ($generation === null) {
            return null;
        }

        return [
            'generation_id' => $generation->public_id,
            'image_url' => route('account.player-character.generations.image', [
                'generation' => $generation->public_id,
            ]).'?v='.$generation->updated_at?->getTimestamp(),
        ];
    }
}
