<?php

namespace App\Modules\Ai\Infrastructure\Gateways;

use App\Modules\Ai\Application\Contracts\AsynchronousPlayerCharacterAiGateway;
use App\Modules\Ai\Application\Dto\FaceReferenceValidationResult;
use App\Modules\Ai\Application\Dto\GeneratedPlayerCharacterImage;
use App\Modules\Ai\Domain\Exceptions\AiServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

final class GitHubOpenAiPlayerCharacterAiGateway implements AsynchronousPlayerCharacterAiGateway
{
    public function validateFaceReferences(array $references): array
    {
        $results = [];

        foreach ($references as $slot => $reference) {
            $contents = (string) ($reference['contents'] ?? '');
            $image = $contents === '' ? false : @getimagesizefromstring($contents);
            $valid = is_array($image) && ($image[0] ?? 0) >= 128 && ($image[1] ?? 0) >= 128;

            $results[$slot] = new FaceReferenceValidationResult(
                valid: $valid,
                detectedSlot: $valid ? $slot : null,
                reason: $valid ? null : 'Фотография лица повреждена или имеет слишком маленький размер.',
            );
        }

        return $results;
    }

    public function generatePlayerCharacter(array $payload): GeneratedPlayerCharacterImage
    {
        $generationId = trim((string) ($payload['generation_id'] ?? ''));

        if ($generationId === '') {
            throw AiServiceException::generationFailed();
        }

        $ttlMinutes = max(5, (int) config('services.github_openai.manifest_ttl_minutes', 20));
        $manifestUrl = URL::temporarySignedRoute(
            'integrations.github-openai.player-character.manifest',
            now()->addMinutes($ttlMinutes),
            ['generation' => $generationId],
        );

        try {
            $response = Http::withToken($this->token())
                ->acceptJson()
                ->withHeaders([
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent' => 'MSKBA-player-character-dispatcher',
                ])
                ->connectTimeout((int) config('services.github_openai.connect_timeout_seconds', 10))
                ->timeout((int) config('services.github_openai.dispatch_timeout_seconds', 30))
                ->post($this->dispatchUrl(), [
                    'ref' => (string) config('services.github_openai.ref', 'main'),
                    'inputs' => [
                        'generation_id' => $generationId,
                        'manifest_url' => $manifestUrl,
                    ],
                ]);
        } catch (ConnectionException) {
            throw AiServiceException::connectionFailed();
        }

        if (! $response->successful()) {
            Log::warning('GitHub OpenAI player generation dispatch failed.', [
                'generation_id' => $generationId,
                'status' => $response->status(),
                'request_id' => $response->header('x-github-request-id'),
            ]);

            if ($response->status() === 429) {
                throw AiServiceException::rateLimited();
            }

            if ($response->status() >= 500) {
                throw AiServiceException::connectionFailed();
            }

            throw AiServiceException::requestRejected();
        }

        Log::info('GitHub OpenAI player generation dispatched.', [
            'generation_id' => $generationId,
            'repository' => (string) config('services.github_openai.repository'),
            'workflow' => (string) config('services.github_openai.workflow'),
            'request_id' => $response->header('x-github-request-id'),
        ]);

        return GeneratedPlayerCharacterImage::pending($generationId);
    }

    private function token(): string
    {
        $token = trim((string) config('services.github_openai.token'));

        if ($token === '') {
            throw AiServiceException::notConfigured();
        }

        return $token;
    }

    private function dispatchUrl(): string
    {
        $repository = trim((string) config('services.github_openai.repository'));
        $workflow = trim((string) config('services.github_openai.workflow'));

        if ($repository === '' || $workflow === '') {
            throw AiServiceException::notConfigured();
        }

        $baseUrl = rtrim((string) config('services.github_openai.api_url', 'https://api.github.com'), '/');

        return sprintf(
            '%s/repos/%s/actions/workflows/%s/dispatches',
            $baseUrl,
            $repository,
            rawurlencode($workflow),
        );
    }
}
