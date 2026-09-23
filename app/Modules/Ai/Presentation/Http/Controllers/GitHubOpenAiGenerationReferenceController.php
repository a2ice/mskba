<?php

namespace App\Modules\Ai\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use App\Modules\Identity\Domain\Support\PlayerCharacterFaceReferenceOptions;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class GitHubOpenAiGenerationReferenceController extends Controller
{
    public function __invoke(PlayerCharacterGeneration $generation, string $slot): Response
    {
        $collection = PlayerCharacterFaceReferenceOptions::collectionForSlot($slot);
        $mediaId = (int) ($generation->reference_media_ids[$slot] ?? 0);
        $profile = $generation->user?->profile;

        abort_if($generation->expires_at?->isPast() || $profile === null || $mediaId < 1, 410);

        $reference = $profile->media()
            ->whereKey($mediaId)
            ->where('collection', $collection)
            ->where('source_reference', PlayerCharacterFaceReferenceOptions::AI_VALIDATED_REFERENCE)
            ->first();

        abort_if($reference === null, 404);

        $disk = Storage::disk($reference->disk);
        abort_unless($disk->exists($reference->path), 404);

        return response(
            $disk->get($reference->path),
            200,
            [
                'Content-Type' => $reference->mime ?: 'image/webp',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
