<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaimDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class VenueOwnershipClaimDocumentController extends Controller
{
    public function download(
        Request $request,
        VenueOwnershipClaim $venueOwnershipClaim,
        VenueOwnershipClaimDocument $document,
    ): StreamedResponse {
        $user = $request->user()?->canonical();
        abort_unless($user !== null, 401);
        abort_unless(
            $user->isSameIdentity($venueOwnershipClaim->applicant_user_id)
                || ($user->isConfirmed() && $user->system_role->atLeast(UserSystemRoleEnum::ADMIN)),
            403,
        );
        abort_unless($document->venue_ownership_claim_id === $venueOwnershipClaim->id, 404);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return response()->streamDownload(
            static fn () => print Storage::disk($document->disk)->get($document->path),
            $document->name,
            ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
