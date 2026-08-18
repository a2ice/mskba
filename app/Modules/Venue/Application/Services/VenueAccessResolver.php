<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Builder;

final class VenueAccessResolver
{
    public function __construct(
        private readonly VenueMembershipAccess $memberships,
    ) {}

    public function canView(?User $user, Venue $venue, ?Actor $actor = null): bool
    {
        if ($venue->status->isPubliclyVisible()) {
            return true;
        }

        if ($user !== null && $this->memberships->allows($user, $venue, VenuePermissionEnum::VIEW)) {
            return true;
        }

        return $this->isBootstrapCreator($user, $venue)
            || $this->isActorCreator($actor, $venue);
    }

    public function canEdit(?User $user, Venue $venue, ?Actor $actor = null): bool
    {
        if (! $venue->allowsDetailsEditing()) {
            return false;
        }

        return $this->canManage($user, $venue, $actor);
    }

    public function canManage(?User $user, Venue $venue, ?Actor $actor = null): bool
    {
        if ($venue->trashed()) {
            return false;
        }

        if ($user !== null && $this->memberships->allows($user, $venue, VenuePermissionEnum::EDIT)) {
            return true;
        }

        return $this->isBootstrapCreator($user, $venue)
            || $this->isActorCreator($actor, $venue);
    }

    public function canRemove(?User $user, Venue $venue, ?Actor $actor = null): bool
    {
        if ($venue->trashed()) {
            return false;
        }

        if ($user !== null && $this->memberships->allows($user, $venue, VenuePermissionEnum::REMOVE)) {
            return true;
        }

        return $this->isBootstrapCreator($user, $venue)
            || $this->isActorCreator($actor, $venue);
    }

    public function canEditSchedule(?User $user, Venue $venue, ?Actor $actor = null): bool
    {
        if (! $venue->allowsOperationalChanges()) {
            return false;
        }

        if ($user !== null && $this->memberships->allows($user, $venue, VenuePermissionEnum::EDIT_SCHEDULE)) {
            return true;
        }

        return $this->isBootstrapCreator($user, $venue)
            || $this->isActorCreator($actor, $venue);
    }

    /** @return array<int> */
    public function contractViewableVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->memberships->allowedVenueIdsFor($user, VenuePermissionEnum::VIEW);
    }

    /** @return array<int> */
    public function contractedVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->memberships->contractedVenueIdsFor($user);
    }

    /** @return array<int> */
    public function bootstrapOwnedVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->memberships->bootstrapOwnedVenueIdsFor($user);
    }

    /** @return array<int> */
    public function actorOwnedVenueIdsFor(?Actor $actor): array
    {
        $user = $actor?->user?->canonical();

        return $user === null
            ? []
            : Venue::query()
                ->whereHas('creatorActor', fn (Builder $query) => $query->whereIn('user_id', $user->identityIds()))
                ->whereNotIn('id', $this->memberships->activeOwnerVenueIds())
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
    }

    /** @return array<int> */
    public function contractEditableVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->memberships->allowedVenueIdsFor($user, VenuePermissionEnum::EDIT);
    }

    /** @return array<int> */
    public function contractScheduleEditableVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->memberships->allowedVenueIdsFor($user, VenuePermissionEnum::EDIT_SCHEDULE);
    }

    private function isBootstrapCreator(?User $user, Venue $venue): bool
    {
        return $user !== null
            && in_array((int) $venue->creatorActor?->user_id, $user->canonical()->identityIds(), true)
            && ! $this->memberships->hasActiveOwner($venue);
    }

    private function isActorCreator(?Actor $actor, Venue $venue): bool
    {
        $user = $actor?->user?->canonical();
        if ($user === null || $this->memberships->hasActiveOwner($venue)) {
            return false;
        }

        $creator = $venue->creatorActor;

        return in_array((int) $creator?->user_id, $user->identityIds(), true);
    }
}
