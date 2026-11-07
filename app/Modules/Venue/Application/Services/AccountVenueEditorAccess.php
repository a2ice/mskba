<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;

/**
 * Account editor capabilities are contract-scoped, not Filament role privileges.
 * A legacy bootstrap creator may finish an unowned venue, but superadmin alone
 * never grants entry to this account editor.
 */
final readonly class AccountVenueEditorAccess
{
    public function __construct(
        private VenueMembershipAccess $memberships,
        private VenueAccessResolver $venues,
        private EntityContractManager $contracts,
    ) {}

    /** @return array<string, bool> */
    public function capabilities(?User $user, Venue $venue): array
    {
        $empty = array_fill_keys([
            'general', 'address', 'facilities', 'photos', 'courts',
            'schedule', 'contracts', 'rental', 'offers', 'memberships',
        ], false);

        if ($user === null || $user->isBlocked() || $user->trashed() || $venue->trashed()) {
            return $empty;
        }

        $user = $user->canonical();
        // The ownerless bootstrap phase retains the post-create workflow.
        $bootstrap = in_array($venue->id, $this->venues->bootstrapOwnedVenueIdsFor($user), true);
        $editContract = $this->memberships->allows($user, $venue, VenuePermissionEnum::EDIT);
        $details = $venue->allowsDetailsEditing() && ($editContract || $bootstrap);
        $schedule = $venue->allowsOperationalChanges() && (
            $this->memberships->allows($user, $venue, VenuePermissionEnum::EDIT_SCHEDULE) || $bootstrap
        );
        $managed = fn (VenuePermissionEnum $permission): bool =>
            $this->memberships->allows($user, $venue, $permission);

        return [
            'general' => $details,
            'address' => $details,
            'facilities' => $details,
            'photos' => $details && ! $venue->hasPendingModerationRequest(),
            'courts' => $details,
            'schedule' => $schedule,
            'contracts' => $this->contracts->allows(
                $user, ContractMembershipScopeTypeEnum::VENUE, $venue->id,
                VenuePermissionEnum::MANAGE_CONTRACTS->value,
            ),
            'rental' => $managed(VenuePermissionEnum::MANAGE_BOOKING_POLICY),
            'offers' => $managed(VenuePermissionEnum::MANAGE_GROUP_OFFERS),
            'memberships' => $managed(VenuePermissionEnum::MANAGE_MEMBERSHIPS),
        ];
    }

    public function canOpen(?User $user, Venue $venue): bool
    {
        return in_array(true, $this->capabilities($user, $venue), true);
    }
}
