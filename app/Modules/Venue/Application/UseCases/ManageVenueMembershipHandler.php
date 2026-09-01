<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Events\VenueMembershipGranted;
use App\Modules\Venue\Domain\Events\VenueMembershipRevoked;
use App\Modules\Venue\Domain\Exceptions\VenueMembershipException;
use App\Modules\Venue\Domain\Models\Venue;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class ManageVenueMembershipHandler
{
    public function __construct(
        private VenueCommercialAccess $access,
        private FeatureFlags $features,
    ) {}

    /** @param array<VenuePermissionEnum>|null $permissions */
    public function grant(
        Venue $venue,
        User $target,
        VenueMembershipAccessLevelEnum $role,
        User $issuer,
        ?array $permissions = null,
    ): ContractMembership {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);
        $target = $target->canonical();
        $issuer = $issuer->canonical();

        if (! $target->isConfirmed() || $target->isBlocked() || $target->trashed()) {
            throw new VenueMembershipException('Коммерческую роль можно выдать только подтверждённому активному пользователю.');
        }

        if (! $role->isCommercialRole() || $role === VenueMembershipAccessLevelEnum::OWNER) {
            throw new VenueMembershipException('Роль владельца выдаётся только через подтверждение владения.');
        }

        return DB::transaction(function () use ($venue, $target, $role, $issuer, $permissions): ContractMembership {
            $venue = Venue::query()->lockForUpdate()->findOrFail($venue->id);
            $this->authorize($issuer, $venue);

            if ($this->activeMemberships($venue)
                ->whereIn('user_id', $target->identityIds())
                ->where('access_level', $role->value)
                ->exists()) {
                throw new VenueMembershipException('У пользователя уже есть эта активная роль на площадке.');
            }

            $contract = Contract::query()->create([
                'family' => ContractFamilyEnum::MEMBERSHIP,
                'name' => "{$role->label()}: {$venue->name}",
                'status' => ContractStatusEnum::ACTIVE,
                'starts_at' => now(),
                'assigned_by' => $issuer->id,
                'assigned_at' => now(),
                'assigner' => UserParticipationRoleAssignerEnum::USER,
                'comment' => $this->overrideComment($issuer),
            ]);

            $membership = $contract->membership()->create([
                'scope_type' => ContractMembershipScopeTypeEnum::VENUE,
                'scope_id' => $venue->id,
                'user_id' => $target->id,
                'access_level' => $role->value,
            ]);

            $this->replacePermissions($contract, $role, $permissions);
            DB::afterCommit(static fn () => event(new VenueMembershipGranted($membership->id)));

            return $membership->load('contract.permissions');
        });
    }

    /** @param array<VenuePermissionEnum>|null $permissions */
    public function change(
        Venue $venue,
        ContractMembership $membership,
        VenueMembershipAccessLevelEnum $role,
        User $issuer,
        ?array $permissions = null,
    ): ContractMembership {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);
        $issuer = $issuer->canonical();

        if (! $role->isCommercialRole() || $role === VenueMembershipAccessLevelEnum::OWNER) {
            throw new VenueMembershipException('Роль владельца изменяется только через отдельный процесс передачи владения.');
        }

        return DB::transaction(function () use ($venue, $membership, $role, $issuer, $permissions): ContractMembership {
            $venue = Venue::query()->lockForUpdate()->findOrFail($venue->id);
            $membership = ContractMembership::query()->with('contract')->lockForUpdate()->findOrFail($membership->id);
            $this->assertBelongsToVenue($membership, $venue);
            $this->authorize($issuer, $venue);

            if ($membership->access_level === VenueMembershipAccessLevelEnum::OWNER->value) {
                throw new VenueMembershipException('Последний владелец не может быть изменён без передачи владения.');
            }

            if ($this->activeMemberships($venue)
                ->where('id', '!=', $membership->id)
                ->where('user_id', $membership->user_id)
                ->where('access_level', $role->value)
                ->exists()) {
                throw new VenueMembershipException('У пользователя уже есть эта активная роль на площадке.');
            }

            $membership->forceFill(['access_level' => $role->value])->save();
            $this->replacePermissions($membership->contract, $role, $permissions);

            return $membership->refresh()->load('contract.permissions');
        });
    }

    public function revoke(Venue $venue, ContractMembership $membership, User $issuer): ContractMembership
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);
        $issuer = $issuer->canonical();

        return DB::transaction(function () use ($venue, $membership, $issuer): ContractMembership {
            $venue = Venue::query()->lockForUpdate()->findOrFail($venue->id);
            $membership = ContractMembership::query()->with('contract')->lockForUpdate()->findOrFail($membership->id);
            $this->assertBelongsToVenue($membership, $venue);
            $this->authorize($issuer, $venue);

            if ($membership->access_level === VenueMembershipAccessLevelEnum::OWNER->value) {
                throw new VenueMembershipException('Нельзя отозвать последнего владельца без передачи владения.');
            }

            $membership->contract->forceFill([
                'status' => ContractStatusEnum::INACTIVE,
                'expires_at' => now(),
                'comment' => trim(implode(' ', array_filter([
                    $membership->contract->comment,
                    "Отозвано пользователем #{$issuer->id}.",
                    $this->overrideComment($issuer),
                ]))),
            ])->save();
            DB::afterCommit(static fn () => event(new VenueMembershipRevoked($membership->id)));

            return $membership->refresh()->load('contract.permissions');
        });
    }

    private function authorize(User $issuer, Venue $venue): void
    {
        if (! $this->access->allows($issuer, $venue, VenuePermissionEnum::MANAGE_MEMBERSHIPS)) {
            throw new VenueMembershipException('Недостаточно прав для управления коммерческими ролями площадки.');
        }
    }

    private function activeMemberships(Venue $venue): Builder
    {
        $now = now();

        return ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::VENUE->value)
            ->where('scope_id', $venue->id)
            ->whereHas('contract', fn (Builder $query) => $query
                ->where('status', ContractStatusEnum::ACTIVE->value)
                ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now)));
    }

    /** @param array<VenuePermissionEnum>|null $permissions */
    private function replacePermissions(
        Contract $contract,
        VenueMembershipAccessLevelEnum $role,
        ?array $permissions,
    ): void {
        $permissions ??= $role->defaultPermissions();
        $allowed = array_map(static fn (VenuePermissionEnum $permission): string => $permission->value, $role->allowedPermissions());
        $values = array_values(array_unique(array_map(
            static fn (VenuePermissionEnum $permission): string => $permission->value,
            $permissions,
        )));

        if (array_diff($values, $allowed) !== []) {
            throw new VenueMembershipException('Выбранные права не соответствуют коммерческой роли.');
        }

        $contract->permissions()->delete();
        $contract->permissions()->createMany(array_map(
            static fn (string $permission): array => ['permission' => $permission],
            $values,
        ));
    }

    private function assertBelongsToVenue(ContractMembership $membership, Venue $venue): void
    {
        if ($membership->scope_type !== ContractMembershipScopeTypeEnum::VENUE || $membership->scope_id !== $venue->id) {
            throw new VenueMembershipException('Роль не относится к выбранной площадке.');
        }
    }

    private function overrideComment(User $issuer): ?string
    {
        return $issuer->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)
            ? "Emergency superadmin override by user #{$issuer->id}."
            : null;
    }
}
