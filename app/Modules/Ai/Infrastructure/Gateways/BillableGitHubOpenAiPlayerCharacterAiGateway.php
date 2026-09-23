<?php

namespace App\Modules\Ai\Infrastructure\Gateways;

use App\Modules\Ai\Application\Contracts\AsynchronousPlayerCharacterAiGateway;
use App\Modules\Ai\Application\Dto\GeneratedPlayerCharacterImage;
use App\Modules\Ai\Application\Services\PlayerCharacterGenerationBilling;
use App\Modules\Ai\Domain\Enums\PlayerCharacterGenerationStatusEnum;
use App\Modules\Ai\Domain\Exceptions\AiServiceException;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use Throwable;

final readonly class BillableGitHubOpenAiPlayerCharacterAiGateway implements AsynchronousPlayerCharacterAiGateway
{
    public function __construct(
        private GitHubOpenAiPlayerCharacterAiGateway $gateway,
        private PlayerCharacterGenerationBilling $billing,
    ) {}

    public function validateFaceReferences(array $references): array
    {
        return $this->gateway->validateFaceReferences($references);
    }

    public function generatePlayerCharacter(array $payload): GeneratedPlayerCharacterImage
    {
        $generationId = trim((string) ($payload['generation_id'] ?? ''));
        $canonicalOwnerId = (int) ($payload['user_id'] ?? 0);

        $generation = $generationId === ''
            ? null
            : PlayerCharacterGeneration::query()->where('public_id', $generationId)->first();

        if ($generation === null || $canonicalOwnerId < 1) {
            throw AiServiceException::generationFailed();
        }

        $olderActiveGenerationExists = PlayerCharacterGeneration::query()
            ->where('user_id', $generation->user_id)
            ->where('id', '<', $generation->id)
            ->whereIn('status', [
                PlayerCharacterGenerationStatusEnum::PENDING->value,
                PlayerCharacterGenerationStatusEnum::PROCESSING->value,
            ])
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if ($olderActiveGenerationExists) {
            $generation->forceFill([
                'status' => PlayerCharacterGenerationStatusEnum::FAILED,
                'error_code' => 'generation_in_progress',
                'error_message' => 'Другая генерация модели уже выполняется.',
                'failed_at' => now(),
            ])->save();

            throw new AiServiceException(
                'generation_in_progress',
                'Другая генерация модели уже выполняется.',
                409,
            );
        }

        $this->billing->charge($generation, $canonicalOwnerId);

        try {
            return $this->gateway->generatePlayerCharacter($payload);
        } catch (Throwable $exception) {
            $this->billing->refund($generation);
            throw $exception;
        }
    }
}
