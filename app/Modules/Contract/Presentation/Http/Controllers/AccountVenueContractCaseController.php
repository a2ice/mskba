<?php

namespace App\Modules\Contract\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Invitation\Domain\Models\AccountInvitation;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Application\Services\VenueUserRestrictionService;
use App\Modules\Venue\Application\Services\VenueOwnershipClaimDraftManager;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum as ClaimStatus;
use App\Modules\Venue\Domain\Enums\VenueUserRestrictionTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\Venue\Domain\Models\VenueOwnership;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class AccountVenueContractCaseController extends Controller
{
    public function show(
        Request $request,
        Venue $venue,
        VenueUserRestrictionService $restrictions,
        VenueMembershipAccess $venueMemberships,
        VenueAccessResolver $venueAccess,
    ): Response {
        $user = $request->user()->canonical();
        abort_if($user->isBlocked() || $user->trashed(), 403);
        $ids = $user->identityIds();
        $reviewer = $user->isConfirmed() && $user->system_role->atLeast(UserSystemRoleEnum::ADMIN);
        $invitation = AccountInvitation::query()
            ->whereIn('accepted_user_id', $ids)->where('scope_type', 'venue')
            ->where('scope_id', $venue->id)->latest('id')->first();
        $claims = VenueOwnershipClaim::query()
            ->where('venue_id', $venue->id)
            ->whereIn('applicant_user_id', $ids)
            ->with(['documents', 'ownership.contractMembership', 'reviewer'])
            ->latest('id')->get();
        $courtIds = VenueCourt::query()->where('venue_id', $venue->id)->pluck('id');
        $contracts = ContractMembership::query()->whereIn('user_id', $ids)
            ->where(function ($query) use ($venue, $courtIds): void {
                $query->where(function ($query) use ($venue): void {
                    $query->where('scope_type', ContractMembershipScopeTypeEnum::VENUE->value)
                        ->where('scope_id', $venue->id);
                })->orWhere(function ($query) use ($courtIds): void {
                    $query->where('scope_type', ContractMembershipScopeTypeEnum::VENUE_COURT->value)
                        ->whereIn('scope_id', $courtIds);
                });
            })->with(['contract.permissions'])->get();
        $bootstrap = in_array($venue->id, $venueAccess->bootstrapOwnedVenueIdsFor($user), true);
        abort_unless($reviewer || $invitation || $claims->isNotEmpty() || $contracts->isNotEmpty() || $bootstrap, 403);

        $selected = $claims->first(fn (VenueOwnershipClaim $c) => $c->status === ClaimStatus::PENDING)
            ?? $claims->first(fn (VenueOwnershipClaim $c) => $c->status === ClaimStatus::DRAFT)
            ?? $claims->first();
        if ($reviewer && $request->filled('claim')) {
            $selected = VenueOwnershipClaim::query()->where('venue_id', $venue->id)
                ->where('public_id', $request->query('claim'))
                ->whereNotNull('submitted_at')->firstOrFail();
        } elseif ($request->filled('claim')) {
            $selected = $claims->firstWhere('public_id', (string) $request->query('claim'));
            abort_unless($selected, 403);
        }
        $selected?->load(['documents', 'reviewer', 'ownership.contractMembership.contract', 'conversation.messages.authorActor.user']);

        $restriction = $restrictions->active($venue, $user, VenueUserRestrictionTypeEnum::OWNERSHIP_CLAIM);
        $ownership = VenueOwnership::query()->where('venue_id', $venue->id)
            ->whereIn('owner_user_id', $ids)->where('active_marker', true)->first();
        $alreadyOwned = $venueMemberships->hasActiveOwner($venue);
        $hasUnfinished = $claims->contains(fn (VenueOwnershipClaim $c) =>
            in_array($c->status, [ClaimStatus::DRAFT, ClaimStatus::PENDING], true));

        return ThemeResolver::page('account.venue-contract-case', [
            'venue' => $venue,
            'invitation' => $invitation,
            'claims' => $claims,
            'claim' => $selected,
            'contracts' => $contracts,
            'ownership' => $ownership,
            'restriction' => $restriction,
            'reviewer' => $reviewer,
            'canStartClaim' => ! $reviewer && ! $alreadyOwned && ! $restriction && ! $hasUnfinished,
            'alreadyOwned' => $alreadyOwned,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function start(
        Request $request,
        Venue $venue,
        VenueUserRestrictionService $restrictions,
        VenueMembershipAccess $memberships,
        VenueAccessResolver $venueAccess,
        VenueOwnershipClaimDraftManager $drafts,
    ): RedirectResponse {
        $user = $request->user()->canonical();
        abort_if($user->isBlocked() || $user->trashed(), 403);
        abort_unless($user->isConfirmed() || $user->hasVerifiedPrimaryContact(), 403);
        $ids = $user->identityIds();
        $allowed = AccountInvitation::query()->whereIn('accepted_user_id', $ids)
            ->where('scope_type', 'venue')->where('scope_id', $venue->id)->exists()
            || VenueOwnershipClaim::query()->where('venue_id', $venue->id)
                ->whereIn('applicant_user_id', $ids)->exists()
            || in_array($venue->id, $venueAccess->bootstrapOwnedVenueIdsFor($user), true);
        abort_unless($allowed, 403);

        $claim = DB::transaction(function () use ($venue, $user, $ids, $drafts, $restrictions, $memberships): VenueOwnershipClaim {
            Venue::query()->lockForUpdate()->findOrFail($venue->id);
            abort_if($memberships->hasActiveOwner($venue), 409, 'У площадки уже есть подтверждённый представитель.');
            abort_if($restrictions->active($venue, $user, VenueUserRestrictionTypeEnum::OWNERSHIP_CLAIM), 403, 'Повторная подача заблокирована администрацией.');
            $existing = VenueOwnershipClaim::query()
                ->where('venue_id', $venue->id)->whereIn('applicant_user_id', $ids)
                ->whereIn('status', [ClaimStatus::DRAFT->value, ClaimStatus::PENDING->value])
                ->latest('id')->first();
            abort_if($existing, 409, 'Заявка или черновик уже существует.');
            $last = VenueOwnershipClaim::query()->where('venue_id', $venue->id)
                ->whereIn('applicant_user_id', $ids)->latest('id')->first();
            return $drafts->create($venue, $user, $last?->evidence ?? '', []);
        });

        return redirect()->route('account.venue-contracts.show', ['venue' => $venue->id, 'claim' => $claim->public_id])
            ->with('status', 'Черновик создан. Дополните объяснение, приложите документы и отправьте на рассмотрение.');
    }
}
