<?php

namespace App\Modules\Ai\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Application\Services\PlayerCharacterGenerationPromptBuilder;
use App\Modules\Ai\Domain\Enums\PlayerCharacterGenerationStatusEnum;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use App\Modules\Identity\Domain\Support\PlayerCharacterFaceReferenceOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;

final class GitHubOpenAiGenerationManifestController extends Controller
{
    public function __invoke(
        PlayerCharacterGeneration $generation,
        PlayerCharacterGenerationPromptBuilder $promptBuilder,
    ): JsonResponse {
        abort_unless(
            in_array($generation->status, [
                PlayerCharacterGenerationStatusEnum::PENDING,
                PlayerCharacterGenerationStatusEnum::PROCESSING,
            ], true),
            410,
        );

        abort_if($generation->expires_at?->isPast(), 410);

        $ttlMinutes = max(5, (int) config('services.github_openai.manifest_ttl_minutes', 20));
        $referenceUrls = [];

        foreach (PlayerCharacterFaceReferenceOptions::SLOTS as $slot) {
            if (! isset($generation->reference_media_ids[$slot])) {
                continue;
            }

            $referenceUrls[$slot] = URL::temporarySignedRoute(
                'integrations.github-openai.player-character.reference',
                now()->addMinutes($ttlMinutes),
                ['generation' => $generation->public_id, 'slot' => $slot],
            );
        }

        abort_if($referenceUrls === [], 422);

        return response()->json([
            'generation_id' => $generation->public_id,
            'prompt' => $promptBuilder->build((array) $generation->payload_snapshot),
            'references' => $referenceUrls,
            'callback_url' => route('integrations.github-openai.player-character.callback', [
                'generation' => $generation->public_id,
            ]),
            'openai' => [
                'model' => (string) config('services.openai.image_model', 'gpt-image-2'),
                'size' => (string) config('services.openai.image_size', '1024x1536'),
                'quality' => (string) config('services.github_openai.image_quality', 'high'),
                'background' => 'transparent',
                'output_format' => 'png',
            ],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}
