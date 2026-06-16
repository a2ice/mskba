<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\ActorClaim;
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
            || $this->isClaimedCreator($user, $venue)
            || $this->isActorCreator($actor, $venue);
    }

    public function canEdit(?User $user, Venue $venue, ?Actor $actor = null): bool
    {
        if ($user !== null && $this->memberships->allows($user, $venue, VenuePermissionEnum::EDIT)) {
            return true;
        }

        return $this->isBootstrapCreator($user, $venue)
            || $this->isClaimedCreator($user, $venue)
            || $this->isActorCreator($actor, $venue);
    }

    public function canRemove(?User $user, Venue $venue, ?Actor $actor = null): bool
    {
        if ($user !== null && $this->memberships->allows($user, $venue, VenuePermissionEnum::REMOVE)) {
            return true;
        }

        return $this->isBootstrapCreator($user, $venue)
            || $this->isClaimedCreator($user, $venue)
            || $this->isActorCreator($actor, $venue);
    }

    public function canEditSchedule(?User $user, Venue $venue, ?Actor $actor = null): bool
    {
        if ($user !== null && $this->memberships->allows($user, $venue, VenuePermissionEnum::EDIT_SCHEDULE)) {
            return true;
        }

        return $this->isBootstrapCreator($user, $venue)
            || $this->isClaimedCreator($user, $venue)
            || $this->isActorCreator($actor, $venue);
    }

    /**
     * @return array<int>
     */
    public function contractViewableVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->memberships->allowedVenueIdsFor($user, VenuePermissionEnum::VIEW);
    }

    /**
     * @return array<int>
     */
    public function contractedVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->memberships->contractedVenueIdsFor($user);
    }

    /**
     * @return array<int>
     */
    public function bootstrapOwnedVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->memberships->bootstrapOwnedVenueIdsFor($user);
    }

    /**
     * @return array<int>
     */
    public function actorOwnedVenueIdsFor(?Actor $actor): array
    {
        $claimedActorIds = $this->claimedActorIdsFor($actor);

        return $actor === null
            ? []
            : Venue::query()
                ->where(function (Builder $query) use ($actor, $claimedActorIds): void {
                    $query->where('created_by_actor_id', $actor->id);

                    if ($claimedActorIds !== []) {
                        $query->orWhereIn('created_by_actor_id', $claimedActorIds);
                    }

                    if ($actor->user_id !== null || $actor->user_fingerprint_id !== null) {
                        $query->orWhereHas('creatorActor', function (Builder $query) use ($actor): void {
                            $query
                                ->when($actor->user_id !== null, fn (Builder $query) => $query->orWhere('user_id', $actor->user_id))
                                ->when($actor->user_fingerprint_id !== null, fn (Builder $query) => $query->orWhere('user_fingerprint_id', $actor->user_fingerprint_id));
                        });
                    }
                })
                ->whereNotIn('id', $this->memberships->activeOwnerVenueIds())
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
    }

    /**
     * @return array<int>
     */
    public function contractEditableVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->memberships->allowedVenueIdsFor($user, VenuePermissionEnum::EDIT);
    }

    /**
     * @return array<int>
     */
    public function contractScheduleEditableVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->memberships->allowedVenueIdsFor($user, VenuePermissionEnum::EDIT_SCHEDULE);
    }

    private function isBootstrapCreator(?User $user, Venue $venue): bool
    {
        return $user !== null
            && $venue->creatorActor?->user_id === $user->id
            && ! $this->memberships->hasActiveOwner($venue);
    }

    private function isClaimedCreator(?User $user, Venue $venue): bool
    {
        return $user !== null
            && $venue->created_by_actor_id !== null
            && ! $this->memberships->hasActiveOwner($venue)
            && ActorClaim::query()
                ->where('claimed_actor_id', $venue->created_by_actor_id)
                ->where('claimed_by_user_id', $user->id)
                ->exists();
    }

    private function isActorCreator(?Actor $actor, Venue $venue): bool
    {
        if ($actor === null || $this->memberships->hasActiveOwner($venue)) {
            return false;
        }

        if ($venue->created_by_actor_id === $actor->id) {
            return true;
        }

        $creator = $venue->creatorActor;

        return $creator !== null
            && (
                ($actor->user_id !== null && $creator->user_id === $actor->user_id)
                || ($actor->user_fingerprint_id !== null && $creator->user_fingerprint_id === $actor->user_fingerprint_id)
            );
    }

    /**
     * @return array<int>
     */
    private function claimedActorIdsFor(?Actor $actor): array
    {
        if ($actor?->user_id === null) {
            return [];
        }

        return ActorClaim::query()
            ->where('claimed_by_user_id', $actor->user_id)
            ->pluck('claimed_actor_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
