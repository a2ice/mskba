<?php

namespace App\Modules\Contract\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Application\Services\UserPrivacyAccessService;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\League\Domain\Enums\LeaguePermissionEnum;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\League\Domain\Enums\SeasonPermissionEnum;
use App\Modules\League\Domain\Models\League;
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
    public const COMMENT_MARKER = 'entity-contract-management:v1';

    /** @return list<\BackedEnum> */
    public function permissionCatalog(Scope $scope): array
    {
        return match ($scope) {
            Scope::LEAGUE => LeaguePermissionEnum::cases(),
            Scope::SEASON => SeasonPermissionEnum::cases(),
            Scope::TOURNAMENT => TournamentPermissionEnum::cases(),
            Scope::TEAM => TeamPermissionEnum::cases(),
            // Ordinary public contracts may manage venue catalogue data only.
            // Commercial rental permissions are delegated through the verified
            // venue ownership/commercial roles workflow, never this editor.
            Scope::VENUE => [
                VenuePermissionEnum::VIEW,
                VenuePermissionEnum::EDIT,
                VenuePermissionEnum::EDIT_SCHEDULE,
                VenuePermissionEnum::REMOVE,
                VenuePermissionEnum::MANAGE_CONTRACTS,
            ],
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
            Scope::VENUE => VenuePermissionEnum::MANAGE_CONTRACTS->value,
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
            'comment' => self::COMMENT_MARKER,
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
            $this->allPermissions($scope),
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

    public function isOwner(User $user, Scope $scope, int $scopeId): bool
    {
        $user = $user->canonical();
        if (! $user->isConfirmed() || $user->isBlocked() || $user->trashed()) {
            return false;
        }
        return $this->membershipQuery($scope, $scopeId)
            ->where('access_level', $scope === Scope::VENUE ? 'primary_manager' : 'owner')
            ->whereIn('user_id', $user->identityIds())
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn ($q) => $q->where('status', ContractStatusEnum::ACTIVE->value))
            ->exists();
    }

    /** Ownership transfer is explicit and requires the future owner to accept. */
    public function transferOwner(Scope $scope, int $scopeId, User $issuer, User $target): ContractMembership
    {
        if ($scope === Scope::VENUE || $this->allPermissions($scope) === []) {
            throw ValidationException::withMessages(['username' => 'Для этой сущности действует отдельный порядок передачи владения.']);
        }
        return DB::transaction(function () use ($scope, $scopeId, $issuer, $target): ContractMembership {
            $issuer = $issuer->canonical();
            $target = $target->canonical();
            if (! $target->isConfirmed() || $target->isBlocked() || $target->trashed()
                || $target->id === $issuer->id) {
                throw ValidationException::withMessages(['username' => 'Выберите другого подтверждённого пользователя.']);
            }
            $owner = $this->membershipQuery($scope, $scopeId)
                ->where('access_level', 'owner')
                ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                ->whereHas('contract', fn ($q) => $q->where('status', ContractStatusEnum::ACTIVE->value))
                ->lockForUpdate()->first();
            if ($owner === null || ! in_array((int) $owner->user_id, $issuer->identityIds(), true)) {
                abort(403);
            }
            if ($this->membershipQuery($scope, $scopeId)
                ->where('access_level', 'owner_candidate')
                ->where('invitation_status', TeamInvitationStatusEnum::PENDING->value)
                ->whereHas('contract', fn ($q) => $q->where('status', ContractStatusEnum::ACTIVE->value))
                ->exists()) {
                throw ValidationException::withMessages(['username' => 'Сначала завершите ранее начатую передачу владения.']);
            }
            $now = now();
            $contract = Contract::query()->create([
                'family' => ContractFamilyEnum::MEMBERSHIP,
                'name' => 'Передача владения: '.$scope->label(),
                'status' => ContractStatusEnum::ACTIVE,
                'starts_at' => $now,
                'assigned_by' => $issuer->id,
                'assigned_at' => $now,
                'assigner' => UserParticipationRoleAssignerEnum::USER,
            'comment' => self::COMMENT_MARKER,
            ]);
            $membership = $contract->membership()->create([
                'scope_type' => $scope,
                'scope_id' => $scopeId,
                'user_id' => $target->id,
                'access_level' => 'owner_candidate',
                'invitation_status' => TeamInvitationStatusEnum::PENDING,
            ]);
            $contract->permissions()->createMany(array_map(
                static fn (string $p): array => ['permission' => $p], $this->allPermissions($scope),
            ));
            $this->notifyInvitation($target->id, $scope->label(), $contract->name);
            return $membership->load('contract.permissions');
        });
    }

    /** @param list<string> $permissions */
    public function invite(Scope $scope, int $scopeId, User $issuer, User $target, string $name, array $permissions): ContractMembership
    {
        return DB::transaction(function () use ($scope, $scopeId, $issuer, $target, $name, $permissions): ContractMembership {
            $issuer = $issuer->canonical();
            $target = $target->canonical();
            if (! $target->isConfirmed() || $target->isBlocked() || $target->trashed()
                || $issuer->id === $target->id
                || ! app(UserPrivacyAccessService::class)->allows($target, $issuer, UserPrivacySettingTypeEnum::GROUP_INVITATIONS)) {
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
                ->whereHas('contract', fn ($q) => $q->where('status', ContractStatusEnum::ACTIVE->value)
                    ->where('comment', self::COMMENT_MARKER)->where('name', trim($name)))
                ->exists();
            if ($existing) {
                throw ValidationException::withMessages(['user_id' => 'Контракт с таким названием уже назначен пользователю.']);
            }

            $contract = Contract::query()->create([
                'family' => ContractFamilyEnum::MEMBERSHIP,
                'name' => trim($name),
                'status' => ContractStatusEnum::ACTIVE,
                'starts_at' => now(),
                'assigned_by' => $issuer->id,
                'assigned_at' => now(),
                'assigner' => UserParticipationRoleAssignerEnum::USER,
            'comment' => self::COMMENT_MARKER,
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
                || $membership->contract?->status !== ContractStatusEnum::ACTIVE
                || $membership->contract?->comment !== self::COMMENT_MARKER) {
                abort(403);
            }
            if ($accepted && $membership->access_level === 'owner_candidate') {
                $owner = $this->membershipQuery($membership->scope_type, (int) $membership->scope_id)
                    ->where('access_level', 'owner')
                    ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                    ->whereHas('contract', fn ($q) => $q->where('status', ContractStatusEnum::ACTIVE->value))
                    ->with('contract')->lockForUpdate()->first();
                if ($owner === null || (int) $owner->user_id !== (int) $membership->contract->assigned_by) {
                    throw ValidationException::withMessages(['decision' => 'Передача больше недоступна.']);
                }
                $owner->contract->update(['status' => ContractStatusEnum::INACTIVE, 'expires_at' => now()]);
                $owner->update(['invitation_status' => TeamInvitationStatusEnum::REVOKED]);
                $membership->update([
                    'access_level' => 'owner',
                    'invitation_status' => TeamInvitationStatusEnum::ACCEPTED,
                ]);
                if ($membership->scope_type === Scope::LEAGUE) {
                    League::query()->whereKey($membership->scope_id)
                        ->update(['owner_user_id' => $membership->user_id]);
                }
                return;
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
            if (in_array($membership->access_level, ['owner', 'primary_manager'], true)
                || $membership->contract?->comment !== self::COMMENT_MARKER) {
                abort(403);
            }
            $available = $this->effectivePermissions($issuer, $membership->scope_type, (int) $membership->scope_id);
            $this->assertDelegation($membership->scope_type, $available,
                $membership->contract->permissions->pluck('permission')->all(), false);
            $membership->update(['invitation_status' => TeamInvitationStatusEnum::REVOKED]);
            $membership->contract->update(['status' => ContractStatusEnum::INACTIVE, 'expires_at' => now()]);
        });
    }

    /** @param list<string> $available @param list<string> $requested */
    private function assertDelegation(Scope $scope, array $available, array $requested, bool $strictlyLower = true): void
    {
        $manage = $this->managePermission($scope);
        if ($manage === null || ! in_array($manage, $available, true)) {
            abort(403);
        }
        if ($requested === [] || count($requested) !== count(array_unique($requested))
            || array_diff($requested, $this->allPermissions($scope)) !== []
            || array_diff($requested, $available) !== []
            || ($strictlyLower && array_diff($available, $requested) === [])) {
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
