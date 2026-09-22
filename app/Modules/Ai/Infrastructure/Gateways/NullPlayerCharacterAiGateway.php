<?php

namespace App\Modules\Ai\Infrastructure\Gateways;

use App\Modules\Ai\Application\Contracts\PlayerCharacterAiGateway;
use App\Modules\Ai\Application\Dto\GeneratedPlayerCharacterImage;
use App\Modules\Ai\Domain\Exceptions\AiServiceException;

final class NullPlayerCharacterAiGateway implements PlayerCharacterAiGateway
{
    public function validateFaceReferences(array $references): array
    {
        throw AiServiceException::notConfigured();
    }

    public function generatePlayerCharacter(array $payload): GeneratedPlayerCharacterImage
    {
        throw AiServiceException::notConfigured();
    }
}
