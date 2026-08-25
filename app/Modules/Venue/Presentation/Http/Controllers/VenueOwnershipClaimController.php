<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Venue\Application\UseCases\CancelVenueOwnershipClaimHandler;
use App\Modules\Venue\Application\UseCases\SubmitVenueOwnershipClaimHandler;
use App\Modules\Venue\Domain\Exceptions\VenueOwnershipClaimException;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class VenueOwnershipClaimController extends Controller
{
    public function create(Request $request, Venue $venue): Response
    {
        abort_unless($request->user()->canonical()->isConfirmed(), 403);

        return ThemeResolver::page('venues.ownership-claim', [
            'venue' => $venue,
            'claims' => VenueOwnershipClaim::query()
                ->where('venue_id', $venue->id)
                ->whereIn('applicant_user_id', $request->user()->canonical()->identityIds())
                ->latest('id')
                ->get(),
        ]);
    }

    public function store(
        Request $request,
        Venue $venue,
        SubmitVenueOwnershipClaimHandler $submit,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validate([
            'evidence' => ['required', 'string', 'min:20', 'max:5000'],
        ]);

        try {
            $claim = $submit->handle($venue, $request->user(), $validated['evidence']);
        } catch (VenueOwnershipClaimException $exception) {
            return $this->error($request, $exception->getMessage(), route('venues.ownership-claims.create', $venue));
        }

        if ($request->expectsJson()) {
            return response()->json(['claim_id' => $claim->id, 'status' => $claim->status->value], 201);
        }

        return redirect()->route('account.venue-ownership-claims.show', $claim)->with('status', 'Заявка отправлена.');
    }

    public function show(Request $request, VenueOwnershipClaim $venueOwnershipClaim): Response
    {
        $user = $request->user()->canonical();

        abort_unless(
            $user->isSameIdentity($venueOwnershipClaim->applicant_user_id)
                || ($user->isConfirmed() && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)),
            403,
        );

        return ThemeResolver::page('venues.ownership-claim-details', [
            'claim' => $venueOwnershipClaim->load(['venue', 'reviewer']),
        ]);
    }

    public function cancel(
        Request $request,
        VenueOwnershipClaim $venueOwnershipClaim,
        CancelVenueOwnershipClaimHandler $cancel,
    ): JsonResponse|RedirectResponse {
        try {
            $claim = $cancel->handle($venueOwnershipClaim, $request->user());
        } catch (VenueOwnershipClaimException $exception) {
            return $this->error(
                $request,
                $exception->getMessage(),
                route('account.venue-ownership-claims.show', $venueOwnershipClaim),
            );
        }

        if ($request->expectsJson()) {
            return response()->json(['claim_id' => $claim->id, 'status' => $claim->status->value]);
        }

        return redirect()->route('account.venue-ownership-claims.show', $claim)->with('status', 'Заявка отменена.');
    }

    private function error(Request $request, string $message, string $redirectTo): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return redirect()->to($redirectTo)->withInput()->with('error', $message);
    }
}
