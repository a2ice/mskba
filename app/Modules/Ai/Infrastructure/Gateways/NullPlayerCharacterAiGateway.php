<?php

namespace App\Modules\Ai\Infrastructure\Gateways;

use App\Modules\Ai\Application\Contracts\PlayerCharacterAiGateway;
use App\Modules\Ai\Application\Dto\FaceReferenceValidationResult;
use App\Modules\Ai\Application\Dto\GeneratedPlayerCharacterImage;
use App\Modules\Ai\Domain\Exceptions\AiServiceException;

final class NullPlayerCharacterAiGateway implements PlayerCharacterAiGateway
{
    public function validateFaceReference(
        string $expectedSlot,
        string $imageContents,
        string $mime,
    ): FaceReferenceValidationResult {
        throw AiServiceException::notConfigured();
    }

    public function generatePlayerCharacter(array $payload): GeneratedPlayerCharacterImage
    {
        throw AiServiceException::notConfigured();
    }
}
