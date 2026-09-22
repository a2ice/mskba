<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Support\PlayerCharacterFaceReferenceOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class PlayerCharacterFaceReferencePreviewController extends Controller
{
    public function __invoke(Request $request, string $slot): Response
    {
        $user = $request->user();

        abort_unless(
            $user?->hasActiveRole(UserParticipationRoleEnum::PLAYER->value) === true,
            403,
        );

        $profile = $user->profile;
        abort_if($profile === null, 404);

        $collection = PlayerCharacterFaceReferenceOptions::collectionForSlot($slot);
        $reference = $profile->media()
            ->where('collection', $collection)
            ->where('source_reference', PlayerCharacterFaceReferenceOptions::AI_VALIDATED_REFERENCE)
            ->latest('id')
            ->first();

        abort_if($reference === null, 404);

        $disk = Storage::disk($reference->disk);
        abort_unless($disk->exists($reference->path), 404);

        return response(
            $disk->get($reference->path),
            200,
            [
                'Content-Type' => $reference->mime ?: 'image/webp',
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
