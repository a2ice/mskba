<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Invitation\Application\Services\AccountInvitationAccess;
use App\Modules\Invitation\Domain\Models\AccountInvitation;
use App\Modules\Venue\Application\UseCases\AdminManageVenueContactsHandler;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;

final class VenueContactConnectionStatus
{
    public function canView(?User $actor, Venue $venue): bool
    {
        if (! app(AdminManageVenueContactsHandler::class)->canManage($actor, $venue)
            || ! app(AccountInvitationAccess::class)->canViewAny($actor)) {
            return false;
        }

        return $actor->canonical()->system_role === UserSystemRoleEnum::SUPERADMIN
            || app(VenueMembershipAccess::class)->allows($actor, $venue, VenuePermissionEnum::MANAGE_MEMBERSHIPS);
    }

    /** Read-only projection. A matching address never establishes identity or grants access. */
    public function resolve(?User $actor, Venue $venue, Contact $contact): array
    {
        $empty = ['user' => null, 'contracts' => [], 'invitation' => null];
        if (! $this->canView($actor, $venue)
            || $contact->contactable_type !== $venue->getMorphClass()
            || (int) $contact->contactable_id !== (int) $venue->id
            || $contact->trashed()
            || $contact->type !== ContactTypeEnum::EMAIL) {
            return $empty;
        }
        // Reload the scoped row; do not trust a stale address or an arbitrary model.
        $contact = $venue->contacts()->find($contact->id);
        if ($contact === null || $contact->type !== ContactTypeEnum::EMAIL) {
            return $empty;
        }

        $invitation = app(AccountInvitationAccess::class)->constrain(AccountInvitation::query(), $actor)
            ->where('scope_type', 'venue')->where('scope_id', $venue->id)
            ->where('contact_type', 'email')->where('contact_value', $contact->value)
            ->orderByRaw("CASE WHEN status = 'pending' AND (accepted_user_id IS NOT NULL OR expires_at > ?) THEN 0 ELSE 1 END", [now()])
            ->latest('id')->first();

        $result = $empty;
        if ($invitation !== null) {
            $result['invitation'] = [
                'id' => $invitation->id,
                'status' => $invitation->invitationStatusLabel(),
                'progress' => $invitation->progressLabel(),
            ];
        }

        if (! $contact->hasBeenVerified()) {
            return $result;
        }
        $matches = Contact::query()->where('contactable_type', 'user')
            ->where('type', 'email')->where('value', $contact->value)
            ->whereNotNull('verified_at')->limit(2)->get();
        if ($matches->count() !== 1) {
            return $result;
        }
        $user = User::query()->find($matches->first()->contactable_id)?->canonical();
        if ($user === null || $user->trashed() || $user->isBlocked()) {
            return $result;
        }
        // Reveal an unrelated account only to a superadmin. Delegated staff
        // see an identity after invitation acceptance or active venue membership.
        $memberships = $user->isConfirmed()
            ? app(VenueMembershipAccess::class)->activeVenueMembershipsFor($user)
                ->filter(fn ($membership): bool => $membership->scope_type === ContractMembershipScopeTypeEnum::VENUE
                    && (int) $membership->scope_id === (int) $venue->id)
            : collect();
        if ($actor->canonical()->system_role !== UserSystemRoleEnum::SUPERADMIN
            && $memberships->isEmpty()
            && ($invitation?->accepted_user_id === null || ! $user->isSameIdentity((int) $invitation->accepted_user_id))) {
            return $result;
        }
        $result['user'] = ['username' => $user->username, 'confirmed' => $user->isConfirmed()];
        $result['contracts'] = $memberships->map(fn ($membership): array => [
            'id' => $membership->contract_id,
            'role' => VenueMembershipAccessLevelEnum::tryFrom($membership->access_level)?->label() ?? $membership->access_level,
        ])->values()->all();

        return $result;
    }
}
