<?php

namespace App\Modules\Ai\Application\Contracts;

use App\Modules\Ai\Application\Dto\FaceReferenceValidationResult;
use App\Modules\Ai\Application\Dto\GeneratedPlayerCharacterImage;

interface PlayerCharacterAiGateway
{
    /**
     * Validate all pending face references in one provider request.
     *
     * @param array<string, array{contents: string, mime: string}> $references
     * @return array<string, FaceReferenceValidationResult>
     */
    public function validateFaceReferences(array $references): array;

    /**
     * @param array<string, mixed> $payload
     */
    public function generatePlayerCharacter(array $payload): GeneratedPlayerCharacterImage;
}
