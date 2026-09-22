<?php

namespace App\Modules\Ai\Application\Contracts;

use App\Modules\Ai\Application\Dto\FaceReferenceValidationResult;
use App\Modules\Ai\Application\Dto\GeneratedPlayerCharacterImage;

interface PlayerCharacterAiGateway
{
    public function validateFaceReference(
        string $expectedSlot,
        string $imageContents,
        string $mime,
    ): FaceReferenceValidationResult;

    /**
     * @param array<string, mixed> $payload
     */
    public function generatePlayerCharacter(array $payload): GeneratedPlayerCharacterImage;
}
