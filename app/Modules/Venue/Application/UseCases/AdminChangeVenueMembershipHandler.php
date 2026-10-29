<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\UseCases\AdminEditPersonalProfileHandler;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminChangeVenueMembershipHandler
{
    /**
     * @param list<string> $permissionKeys
     */
    public function handle(User $actor, User $target, int $membershipId, string $roleKey, array $permissionKeys, string $reason): AuditLog
    {
        $actor = $actor->canonical();
        $target = $target->canonical();
        app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите основание изменения (10–500 символов).']);
        }
        $role = VenueMembershipAccessLevelEnum::tryFrom($roleKey);
        if (! $role?->isCommercialRole() || $role === VenueMembershipAccessLevelEnum::OWNER) {
            throw ValidationException::withMessages(['access_level' => 'Владение и служебные роли нельзя изменять через этот редактор.']);
        }
        if (count($permissionKeys) !== count(array_unique($permissionKeys)) || array_filter($permissionKeys, 'is_string') !== $permissionKeys) {
            throw ValidationException::withMessages(['permissions' => 'Неверный список разрешений.']);
        }
        $permissions = [];
        foreach ($permissionKeys as $key) {
            $permission = VenuePermissionEnum::tryFrom($key);
            if ($permission === null || ! in_array($permission, $role->allowedPermissions(), true)) {
                throw ValidationException::withMessages(['permissions' => 'Разрешение не соответствует выбранной роли.']);
            }
            $permissions[] = $permission;
        }

        return DB::transaction(function () use ($actor, $target, $membershipId, $role, $permissions, $reason): AuditLog {
            $membership = ContractMembership::query()->findOrFail($membershipId);
            [$venue, $court] = $this->scope($membership);
            $venue = Venue::query()->whereKey($venue->id)->lockForUpdate()->firstOrFail();
            $membership = ContractMembership::query()->whereKey($membershipId)->lockForUpdate()->firstOrFail();
            // Recheck both the target and the contract's active time window under lock.
            app(AdminEditPersonalProfileHandler::class)->authorize($actor, User::query()->findOrFail($target->id));
            if (! app(VenueMembershipAccess::class)->activeVenueMembershipsFor($target)->contains('id', $membership->id)) {
                throw ValidationException::withMessages(['membership_id' => 'Договор уже не действует или не принадлежит пользователю.']);
            }
            [$checkedVenue, $checkedCourt] = $this->scope($membership);
            if ($checkedVenue->id !== $venue->id || $checkedCourt?->id !== $court?->id) {
                throw ValidationException::withMessages(['membership_id' => 'Область договора изменилась.']);
            }
            $membership->load('contract.permissions');
            $before = $this->snapshot($membership);
            app(ManageVenueMembershipHandler::class)->change($venue, $membership, $role, $actor, $permissions);
            $after = $this->snapshot($membership->fresh()->load('contract.permissions'));
            if ($before === $after) {
                throw ValidationException::withMessages(['membership_id' => 'Роль и права остались без изменений.']);
            }

            return AuditLog::query()->create([
                'actor_id' => app(CurrentActorResolver::class)->resolveForRequest(request())?->id,
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'event' => 'admin_venue_membership_changed',
                'old_values' => $before,
                'new_values' => $after,
                'metadata' => [
                    'reason' => $reason,
                    'membership_id' => $membership->id,
                    'contract_id' => $membership->contract_id,
                    'venue_id' => $venue->id,
                    'court_id' => $court?->id,
                ],
            ]);
        });
    }

    /** @return array{0: Venue, 1: ?VenueCourt} */
    public function scope(ContractMembership $membership): array
    {
        if ($membership->scope_type === ContractMembershipScopeTypeEnum::VENUE) {
            return [Venue::query()->findOrFail($membership->scope_id), null];
        }
        if ($membership->scope_type === ContractMembershipScopeTypeEnum::VENUE_COURT) {
            $court = VenueCourt::query()->with('venue')->findOrFail($membership->scope_id);
            return [$court->venue, $court];
        }
        throw ValidationException::withMessages(['membership_id' => 'Это не договор управления площадкой.']);
    }

    /** @return array{role: string, permissions: list<string>} */
    public function snapshot(ContractMembership $membership): array
    {
        return [
            'role' => (string) $membership->access_level,
            'permissions' => $membership->contract->permissions->pluck('permission')->sort()->values()->all(),
        ];
    }
}
