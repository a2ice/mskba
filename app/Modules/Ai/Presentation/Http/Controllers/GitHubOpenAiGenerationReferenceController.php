<?php

namespace App\Modules\Ai\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ai\Domain\Models\PlayerCharacterGeneration;
use App\Modules\Identity\Domain\Support\PlayerCharacterFaceReferenceOptions;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Uniform\Domain\Models\UniformDesign;
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

        $source = (string) data_get($generation->payload_snapshot, 'team.logo_source', 'none');
        $uniformSource = (string) data_get($generation->payload_snapshot, 'uniform.source', 'neutral');
        $designId = (int) data_get($generation->payload_snapshot, 'uniform.design_id', 0);
        $teamId = (int) data_get($generation->payload_snapshot, 'team.id', 0);
        $design = null;

        if ($designId > 0) {
            $query = UniformDesign::query()->whereKey($designId);
            if ($uniformSource === 'mine') {
                // Personal designs must be owned by the signed-generation user.
                $query->whereNull('team_id')
                    ->whereIn('user_id', $generation->user?->identityIds() ?? []);
            } elseif ($uniformSource === 'team' && $teamId > 0) {
                $query->where('team_id', $teamId);
            } else {
                abort(404);
            }

            $design = $query->first();
            abort_if($design === null, 404);
        }

        if ($source === 'custom' && $design !== null) {
            // Current uniform workshop: custom crests belong to UniformDesign,
            // not the removed player_generation_logos profile collection.
            $logo = $design->customLogo()->whereKey($mediaId)->first();
        } elseif ($source === 'team' && $design !== null) {
            // A personal or team design may choose another permitted team's crest.
            $logo = $design->logoTeam?->logo;
            abort_if($logo === null || (int) $logo->id !== $mediaId, 404);
        } elseif ($source === 'team' && $teamId > 0 && $uniformSource !== 'mine') {
            // Older team uniforms without a workshop design use the club logo.
            $logo = Team::query()->with('logo')->find($teamId)?->logo;
            abort_if($logo === null || (int) $logo->id !== $mediaId, 404);
        } elseif ($source === 'custom' && $designId === 0) {
            // Backward compatibility for already-dispatched, legacy requests.
            $logo = Media::query()->whereKey($mediaId)
                ->where('mediable_type', User::class)
                ->whereIn('mediable_id', $generation->user?->identityIds() ?? [])
                ->where('collection', 'player_generation_logos')->first();
        } else {
            abort(404);
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
