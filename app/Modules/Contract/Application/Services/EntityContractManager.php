<?php

namespace App\Modules\Contract\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Enums\LeaguePermissionEnum;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\League\Domain\Enums\SeasonPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Public-domain entity contracts. Backoffice operational permissions are never
 * consulted here; Filament retains its independent global authorization.
 */
final class EntityContractManager
{
    /** @return list<\BackedEnum> */
    public function permissionCatalog(Scope $scope): array
    {
        return match ($scope) {
            Scope::LEAGUE => LeaguePermissionEnum::cases(),
            Scope::SEASON => SeasonPermissionEnum::cases(),
            Scope::TOURNAMENT => TournamentPermissionEnum::cases(),
            Scope::TEAM => TeamPermissionEnum::cases(),
            Scope::VENUE => VenuePermissionEnum::cases(),
            default => [],
        };
    }

    public function managePermission(Scope $scope): ?string
    {
        return match ($scope) {
            Scope::LEAGUE => LeaguePermissionEnum::MANAGE_CONTRACTS->value,
            Scope::SEASON => SeasonPermissionEnum::MANAGE_CONTRACTS->value,
            Scope::TOURNAMENT => TournamentPermissionEnum::MANAGE_STAFF->value,
            Scope::TEAM => TeamPermissionEnum::MANAGE_PERMISSIONS->value,
            Scope::VENUE => VenuePermissionEnum::MANAGE_MEMBERSHIPS->value,
            default => null,
        };
    }

    /** @return list<string> */
    public function allPermissions(Scope $scope): array
    {
        return array_map(static fn (\BackedEnum $p): string => $p->value, $this->permissionCatalog($scope));
    }

    /**
     * Used inside the same transaction as entity creation. An administrator's
     * choice of a different owner results in a pending invitation, not consent.
     */
    public function grantOwner(Scope $scope, int $scopeId, User $owner, User $issuer, ?string $title = null): ContractMembership
    {
        if ($this->allPermissions($scope) === []) {
            throw ValidationException::withMessages(['owner' => 'Нельзя назначить владельца этой сущности.']);
        }
        $owner = $owner->canonical();
        $issuer = $issuer->canonical();
        if (! $owner->isConfirmed() || $owner->isBlocked() || $owner->trashed()) {
            throw ValidationException::withMessages(['owner' => 'Выберите подтверждённого пользователя.']);
        }

        // Venue catalogue responsibility is not proof of property ownership.
        // A confirmed commercial owner continues to use the separate claim process.
        $ownerRole = $scope === Scope::VENUE ? 'primary_manager' : 'owner';
        $existing = $this->membershipQuery($scope, $scopeId)
            ->where('access_level', $ownerRole)
            ->whereHas('contract', fn ($q) => $q->where('status', ContractStatusEnum::ACTIVE->value))
            ->lockForUpdate()->first();
        if ($existing !== null) {
            if (in_array((int) $existing->user_id, $owner->identityIds(), true)) {
                return $existing;
            }
            throw ValidationException::withMessages(['owner' => 'У сущности уже есть назначенный владелец.']);
        }

        $name = $title !== null && trim($title) !== '' ? trim($title) : 'Владелец: '.$scope->label();
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'name' => $name,
            'status' => ContractStatusEnum::ACTIVE,
            'starts_at' => now(),
            'assigned_by' => $issuer->id,
            'assigned_at' => now(),
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
        $membership = $contract->membership()->create([
            'scope_type' => $scope,
            'scope_id' => $scopeId,
            'user_id' => $owner->id,
            'access_level' => $ownerRole,
            'invitation_status' => $owner->id === $issuer->id
                ? TeamInvitationStatusEnum::ACCEPTED
                : TeamInvitationStatusEnum::PENDING,
        ]);
        $contract->permissions()->createMany(array_map(
            static fn (string $permission): array => ['permission' => $permission],
            $scope === Scope::VENUE ? [
                VenuePermissionEnum::VIEW->value,
                VenuePermissionEnum::EDIT->value,
                VenuePermissionEnum::EDIT_SCHEDULE->value,
                VenuePermissionEnum::REMOVE->value,
                VenuePermissionEnum::MANAGE_MEMBERSHIPS->value,
            ] : $this->allPermissions($scope),
        ));
        if ($owner->id !== $issuer->id) {
            $this->notifyInvitation($owner->id, $scope->label(), $contract->name);
        }
        return $membership->load('contract.permissions');
    }

    /** @return list<string> */
    public function effectivePermissions(User $user, Scope $scope, int $scopeId): array
    {
        $user = $user->canonical();
        if (! $user->isConfirmed() || $user->isBlocked() || $user->trashed()) {
            return [];
        }
        $now = now();
        return $this->membershipQuery($scope, $scopeId)
            ->whereIn('user_id', $user->identityIds())
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn ($q) => $q
                ->where('family', ContractFamilyEnum::MEMBERSHIP->value)
                ->where('status', ContractStatusEnum::ACTIVE->value)
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now)))
            ->with('contract.permissions')
            ->get()->flatMap(fn (ContractMembership $m) => $m->contract->permissions->pluck('permission'))
            ->unique()->values()->all();
    }

    public function allows(User $user, Scope $scope, int $scopeId, string $permission): bool
    {
        return in_array($permission, $this->effectivePermissions($user, $scope, $scopeId), true);
    }

    /** @param list<string> $permissions */
    public function invite(Scope $scope, int $scopeId, User $issuer, User $target, string $name, array $permissions): ContractMembership
    {
        return DB::transaction(function () use ($scope, $scopeId, $issuer, $target, $name, $permissions): ContractMembership {
            $issuer = $issuer->canonical();
            $target = $target->canonical();
            if (! $target->isConfirmed() || $target->isBlocked() || $target->trashed()
                || $issuer->id === $target->id) {
                throw ValidationException::withMessages(['user_id' => 'Выберите другого подтверждённого пользователя.']);
            }
            $available = $this->effectivePermissions($issuer, $scope, $scopeId);
            $this->assertDelegation($scope, $available, $permissions);
            if (trim($name) === '' || mb_strlen(trim($name)) > 150) {
                throw ValidationException::withMessages(['name' => 'Укажите название контракта до 150 символов.']);
            }
            $existing = $this->membershipQuery($scope, $scopeId)
                ->whereIn('user_id', $target->identityIds())
                ->whereIn('invitation_status', [TeamInvitationStatusEnum::PENDING->value, TeamInvitationStatusEnum::ACCEPTED->value])
                ->whereHas('contract', fn ($q) => $q->where('status', ContractStatusEnum::ACTIVE->value))
                ->exists();
            if ($existing) {
                throw ValidationException::withMessages(['user_id' => 'У пользователя уже есть действующий контракт или приглашение.']);
            }

            $contract = Contract::query()->create([
                'family' => ContractFamilyEnum::MEMBERSHIP,
                'name' => trim($name),
                'status' => ContractStatusEnum::ACTIVE,
                'starts_at' => now(),
                'assigned_by' => $issuer->id,
                'assigned_at' => now(),
                'assigner' => UserParticipationRoleAssignerEnum::USER,
            ]);
            $membership = $contract->membership()->create([
                'scope_type' => $scope,
                'scope_id' => $scopeId,
                'user_id' => $target->id,
                'access_level' => 'responsible',
                'invitation_status' => TeamInvitationStatusEnum::PENDING,
            ]);
            $contract->permissions()->createMany(array_map(
                static fn (string $value): array => ['permission' => $value],
                array_values(array_unique($permissions)),
            ));
            $this->notifyInvitation($target->id, $scope->label(), $contract->name);
            return $membership->load('contract.permissions');
        });
    }

    public function respond(ContractMembership $membership, User $user, bool $accepted): void
    {
        DB::transaction(function () use ($membership, $user, $accepted): void {
            $membership = ContractMembership::query()->with('contract')->lockForUpdate()->findOrFail($membership->id);
            if (! in_array((int) $membership->user_id, $user->canonical()->identityIds(), true)
                || $membership->invitation_status !== TeamInvitationStatusEnum::PENDING
                || $membership->contract?->status !== ContractStatusEnum::ACTIVE) {
                abort(403);
            }
            $membership->update(['invitation_status' => $accepted
                ? TeamInvitationStatusEnum::ACCEPTED : TeamInvitationStatusEnum::DECLINED]);
            if (! $accepted) {
                $membership->contract->update(['status' => ContractStatusEnum::INACTIVE, 'expires_at' => now()]);
            }
        });
    }

    public function revoke(ContractMembership $membership, User $issuer): void
    {
        DB::transaction(function () use ($membership, $issuer): void {
            $membership = ContractMembership::query()->with('contract.permissions')->lockForUpdate()->findOrFail($membership->id);
            if (in_array($membership->access_level, ['owner', 'primary_manager'], true)) {
                abort(403);
            }
            $available = $this->effectivePermissions($issuer, $membership->scope_type, (int) $membership->scope_id);
            $this->assertDelegation($membership->scope_type, $available,
                $membership->contract->permissions->pluck('permission')->all());
            $membership->update(['invitation_status' => TeamInvitationStatusEnum::REVOKED]);
            $membership->contract->update(['status' => ContractStatusEnum::INACTIVE, 'expires_at' => now()]);
        });
    }

    /** @param list<string> $available @param list<string> $requested */
    private function assertDelegation(Scope $scope, array $available, array $requested): void
    {
        $manage = $this->managePermission($scope);
        if ($manage === null || ! in_array($manage, $available, true)) {
            abort(403);
        }
        if ($requested === [] || count($requested) !== count(array_unique($requested))
            || array_diff($requested, $this->allPermissions($scope)) !== []
            || array_diff($requested, $available) !== []) {
            throw ValidationException::withMessages(['permissions' => 'Нельзя назначить выбранные права.']);
        }
    }

    private function notifyInvitation(int $userId, string $type, string $name): void
    {
        DB::afterCommit(static function () use ($userId, $type, $name): void {
            app(CreateUserNotificationHandler::class)->handle(new CreateUserNotificationDTO(
                userId: $userId,
                type: UserNotificationTypeEnum::REMINDER,
                title: 'Приглашение к управлению',
                body: 'Вам предложен контракт «'.$name.'» ('.$type.').',
                actionUrl: route('account.contracts'),
                actionText: 'Рассмотреть',
            ));
        });
    }

    private function membershipQuery(Scope $scope, int $scopeId): \Illuminate\Database\Eloquent\Builder
    {
        return ContractMembership::query()->where('scope_type', $scope->value)->where('scope_id', $scopeId);
    }
}
