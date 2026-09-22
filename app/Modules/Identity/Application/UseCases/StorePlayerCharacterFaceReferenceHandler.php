<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Ai\Application\Contracts\PlayerCharacterAiGateway;
use App\Modules\Identity\Domain\Exceptions\PlayerCharacterFlowException;
use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Identity\Domain\Support\PlayerCharacterFaceReferenceOptions;
use App\Modules\Media\Application\Services\WebpImageNormalizer;

final class StorePlayerCharacterFaceReferenceHandler
{
    public function __construct(
        private readonly WebpImageNormalizer $normalizer,
        private readonly PlayerCharacterAiGateway $ai,
        private readonly PersistValidatedPlayerCharacterFaceReferencesHandler $persister,
    ) {}

    /**
     * @return array{media: \App\Modules\Media\Domain\Models\Media, width: int, height: int}
     */
    public function handle(Profile $profile, string $slot, string $contents): array
    {
        $image = $this->normalizer->normalize(
            $contents,
            PlayerCharacterFaceReferenceOptions::MAX_OUTPUT_DIMENSION,
        );

        $validation = $this->ai->validateFaceReferences([
            $slot => [
                'contents' => $image['contents'],
                'mime' => $image['mime'],
            ],
        ])[$slot] ?? null;

        if ($validation === null || ! $validation->valid) {
            $expectedLabel = PlayerCharacterFaceReferenceOptions::labels()[$slot] ?? $slot;
            throw new PlayerCharacterFlowException(
                'face_reference_invalid',
                $validation?->reason ?: 'Фото не соответствует ракурсу «'.$expectedLabel.'».',
                422,
                [
                    'expected_slot' => $slot,
                    'detected_slot' => $validation?->detectedSlot,
                ],
            );
        }

        return $this->persister->handle($profile, [$slot => $image])[$slot];
    }
}
