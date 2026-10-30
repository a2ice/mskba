<?php

namespace App\Modules\Ai\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use App\Modules\Identity\Domain\Support\PlayerCharacterFaceReferenceOptions;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class GitHubOpenAiGenerationReferenceController extends Controller
{
    public function __invoke(PlayerCharacterGeneration $generation, string $slot): Response
    {
        abort_if($generation->expires_at?->isPast(), 410);

        if ($slot === 'team_logo') {
            return $this->teamLogo($generation);
        }

        $collection = PlayerCharacterFaceReferenceOptions::collectionForSlot($slot);
        $mediaId = (int) ($generation->reference_media_ids[$slot] ?? 0);
        $profile = $generation->user?->profile;

        abort_if($profile === null || $mediaId < 1, 410);

        $reference = $profile->media()
            ->whereKey($mediaId)
            ->where('collection', $collection)
            ->where('source_reference', PlayerCharacterFaceReferenceOptions::AI_VALIDATED_REFERENCE)
            ->first();

        abort_if($reference === null, 404);

        return $this->imageResponse($reference->disk, $reference->path, $reference->mime ?: 'image/webp');
    }

    private function teamLogo(PlayerCharacterGeneration $generation): Response
    {
        abort_unless((bool) data_get($generation->payload_snapshot, 'team.with_logo', false), 404);

        $mediaId = (int) ($generation->reference_media_ids['team_logo'] ?? 0);
        abort_if($mediaId < 1, 404);
        if (data_get($generation->payload_snapshot, 'team.logo_source') === 'custom') {
            $logo = Media::query()->whereKey($mediaId)
                ->where('mediable_type', User::class)
                ->whereIn('mediable_id', $generation->user->identityIds())
                ->where('collection', 'player_generation_logos')->first();
        } else {
            $teamId = (int) data_get($generation->payload_snapshot, 'team.id', 0);
            $logo = Team::query()->with('logo')->find($teamId)?->logo;
            abort_if($logo === null || (int) $logo->id !== $mediaId, 404);
        }
        abort_if($logo === null, 404);
        return $this->imageResponse($logo->disk, $logo->path, $logo->mime ?: 'image/webp');
    }

    private function imageResponse(string $diskName, string $path, string $mime): Response
    {
        $disk = Storage::disk($diskName);
        abort_unless($disk->exists($path), 404);

        return response(
            $disk->get($path),
            200,
            [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
