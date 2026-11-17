<?php

namespace App\Presentation\Navigation;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;

/**
 * An edit action is routed by its source of authority, never by a combined
 * "can edit" flag: venue contracts use the account editor, while backoffice
 * administration uses Filament. Bootstrap creation grants no menu shortcut.
 */
final readonly class VenueEditLinkResolver
{
    public function __construct(
        private VenueMembershipAccess $memberships,
    ) {}

    public function url(?User $user, Venue $venue): ?string
    {
        if ($user === null || $venue->trashed() || ! $venue->allowsDetailsEditing()) {
            return null;
        }

        $user = $user->canonical();

        if ($this->memberships->allows($user, $venue, VenuePermissionEnum::EDIT)) {
            return route('account.venues.edit', $venue->routeIdentifier());
        }

        if ($user->isConfirmed()
            && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)
            && VenueResource::canEdit($venue)) {
            return VenueResource::getUrl('edit', ['record' => $venue], panel: 'backoffice');
        }

        return null;
    }
}
