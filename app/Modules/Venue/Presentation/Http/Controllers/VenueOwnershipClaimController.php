<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Application\UseCases\CancelVenueOwnershipClaimHandler;
use App\Modules\Venue\Application\UseCases\SubmitVenueOwnershipClaimHandler;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum;
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
    public function landing(
        Request $request,
        Venue $venue,
        VenueMembershipAccess $memberships,
    ): Response {
        $owner = $memberships->activeOwner($venue);
        $user = $request->user()?->canonical();
        $pendingClaim = null;
        $claimHistory = collect();

        if ($user !== null) {
            $claimHistory = VenueOwnershipClaim::query()
                ->where('venue_id', $venue->id)
                ->whereIn('applicant_user_id', $user->identityIds())
                ->latest('id')
                ->get();
            $pendingClaim = $claimHistory
                ->first(fn (VenueOwnershipClaim $claim): bool => $claim->status === VenueOwnershipClaimStatusEnum::PENDING);
        }

        $identityVerified = $user !== null
            && ($user->isConfirmed() || $user->hasVerifiedPrimaryContact());

        return ThemeResolver::page('venues.ownership', [
            'venue' => $venue,
            'owner' => $owner,
            'currentUser' => $user,
            'pendingClaim' => $pendingClaim,
            'claimHistory' => $claimHistory,
            'canSubmitClaim' => $owner === null
                && $identityVerified
                && $pendingClaim === null,
            'needsAccountConfirmation' => $user !== null && ! $identityVerified,
        ]);
    }

    public function verify(Request $request, Venue $venue): RedirectResponse
    {
        $request->session()->put('url.intended', route('venues.management', $venue));

        return redirect()
            ->route('account.confirmation')
            ->with('info', 'Чтобы подтвердить управление площадкой, сначала подтвердите аккаунт или основной контакт.');
    }

    public function create(Request $request, Venue $venue): RedirectResponse
    {
        $user = $request->user()?->canonical();
        if ($user === null) {
            return redirect()->route('venues.management', $venue);
        }

        if (! $user->isConfirmed() && ! $user->hasVerifiedPrimaryContact()) {
            return $this->verify($request, $venue);
        }

        return redirect()->to(route('venues.management', $venue).'#claim-form');
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
            return $this->error($request, $exception->getMessage(), route('venues.management', $venue));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'claim_id' => $claim->public_id,
                'status' => $claim->status->value,
                'url' => route('account.venue-ownership.show', $claim),
            ], 201);
        }

        return redirect()
            ->route('account.venue-ownership.show', $claim)
            ->with('status', 'Заявка отправлена. Статус будет обновляться автоматически.');
    }

    public function show(Request $request, VenueOwnershipClaim $venueOwnershipClaim): Response
    {
        $user = $request->user()->canonical();
        $isReviewer = $user->isConfirmed() && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN);
        $isApplicant = $user->isSameIdentity($venueOwnershipClaim->applicant_user_id);

        abort_unless($isApplicant || $isReviewer, 403);

        $claim = $venueOwnershipClaim->load([
            'venue',
            'reviewer.profile',
            'applicant.profile',
            'conversation',
        ]);

        return ThemeResolver::page('venues.ownership-claim-details', [
            'claim' => $claim,
            'isReviewer' => $isReviewer,
            'isApplicant' => $isApplicant,
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
                route('account.venue-ownership.show', $venueOwnershipClaim),
            );
        }

        if ($request->expectsJson()) {
            return response()->json([
                'claim_id' => $claim->public_id,
                'status' => $claim->status->value,
                'status_label' => $claim->status->label(),
            ]);
        }

        return redirect()
            ->route('account.venue-ownership.show', $claim)
            ->with('status', 'Заявка отменена.');
    }

    private function error(Request $request, string $message, string $redirectTo): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return redirect()->to($redirectTo)->withInput()->with('error', $message);
    }
}
