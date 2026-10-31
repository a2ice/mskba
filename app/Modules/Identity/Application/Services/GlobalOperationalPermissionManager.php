<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Application\UseCases\AdminUpdateUserOperationalPermissionsHandler;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * An editor for the user's GLOBAL operational permissions. Entity memberships,
 * ownership, team/venue/event permissions are deliberately out of scope.
 * Delegates writes to the existing use case to preserve ticket/review cursors
 * and revocation semantics.
 */
final class GlobalOperationalPermissionManager
{
    /** @return list<Permission> */
    public function creationPermissions(): array
    {
        return [
            Permission::CREATE_COORDINATION, Permission::CREATE_TEAM,
            Permission::CREATE_EVENT, Permission::CREATE_TOURNAMENT,
            Permission::CREATE_LEAGUE, Permission::CREATE_SEASON, Permission::CREATE_VENUE,
        ];
    }

    /** @return list<Permission> */
    public function privilegedPermissions(): array
    {
        return [
            Permission::MANAGE_SYSTEM_ROLES, Permission::REVIEW_PROFILE_CHANGES,
            Permission::EDIT_OWN_PROFILE, Permission::EDIT_USER_PERSONAL_DATA,
            Permission::WALLET_USER_BALANCE_CREDIT,
            Permission::HANDLE_SUPPORT_TICKETS,
            Permission::OPERATOR_PRESENCE_VIEW,
            Permission::OPERATOR_SESSION_HISTORY_VIEW,
            Permission::OPERATOR_CONVERSATION_HANDLE,
            Permission::OPERATOR_MARKETING_SEND,
        ];
    }

    /** @return list<string> */
    /** @return list<string> */
    public function granted(User $target): array
    {
        $checker = app(UserOperationalPermissionChecker::class);
        return array_values(array_map(
            static fn (Permission $p): string => $p->value,
            array_filter(array_merge($this->creationPermissions(), $this->privilegedPermissions()),
                fn (Permission $p): bool => $checker->allows($target, $p))
        ));
    }

    /** @return array<string, string> */
    public function sources(User $target): array
    {
        $target = $target->canonical();
        $snapshots = $target->operationalPermissions()->get()
            ->keyBy(static fn (UserOperationalPermission $p): string => $p->permission->value);
        $sources = [];
        foreach (array_merge($this->creationPermissions(), $this->privilegedPermissions()) as $p) {
            $explicit = $snapshots->get($p->value);
            $sources[$p->value] = $explicit === null
                ? (app(StaffPermissionProfiles::class)->inherited($target, $p) ? 'Наследуется от роли или специализации' : 'Не предоставлено')
                : ($explicit->is_allowed ? 'Выдано индивидуально' : 'Явно запрещено');
        }

        return $sources;
    }

    public function mayManage(User $actor, ?User $target = null): bool
    {
        $actor = $actor->canonical();
        if (! $actor->isConfirmed() || $actor->isBlocked() || $actor->trashed()
            || ! $actor->system_role->atLeast(UserSystemRoleEnum::ADMIN)) {
            return false;
        }

        if ($target === null) {
            return true;
        }

        $target = $target->canonical();

        return $actor->id !== $target->id
            && $target->system_role !== UserSystemRoleEnum::SYSTEM
            && $actor->system_role->numericValue() > $target->system_role->numericValue();
    }

    public function canManagePrivileged(User $actor): bool
    {
        $actor = $actor->canonical();

        return $this->mayManage($actor);
    }

    /**
     * @param list<string> $creation
     * @param list<string>|null $privileged null means "leave unchanged".
     * @param bool $overrideInherited Full editor mode: persist explicit denials of role defaults;
     *                                legacy grant-only callers preserve their historical behavior.
     */
    public function sync(User $actor, User $target, array $creation, ?array $privileged = null, bool $overrideInherited = false): void
    {
        if (! $this->mayManage($actor, $target)) {
            throw new AuthorizationException('Недостаточно прав для изменения глобальных разрешений пользователя.');
        }

        $this->validateSet($creation, $this->creationPermissions(), 'data.creation_permissions');
        if ($privileged !== null) {
            if (! $this->canManagePrivileged($actor)) {
                throw new AuthorizationException('Недостаточно прав для управления критичными разрешениями.');
            }
            $this->validateSet($privileged, $this->privilegedPermissions(), 'data.privileged_permissions');

            // A reviewer must be an admin. UI must not allow a silently
            // ineffective reviewer grant to a regular user.
            if (in_array(Permission::REVIEW_PROFILE_CHANGES->value, $privileged, true)
                && ! $target->canonical()->system_role->atLeast(UserSystemRoleEnum::ADMIN)) {
                throw ValidationException::withMessages([
                    'data.privileged_permissions' => 'Рассмотрение изменений данных разрешено только пользователю с ролью admin.',
                ]);
            }
        }

        $checker = app(UserOperationalPermissionChecker::class);
        $profiles = app(StaffPermissionProfiles::class);
        $all = $privileged === null
            ? $this->creationPermissions()
            : array_merge($this->creationPermissions(), $this->privilegedPermissions());
        // Individual decisions may override ordinary inherited defaults, but never the
        // role ceiling or grants outside the current administrator's own scope.
        $scope = array_values(array_filter($all, static fn (Permission $permission): bool =>
            $profiles->allowedForRole($target->system_role, $permission)
            && ($overrideInherited || ! $profiles->inherited($target->canonical(), $permission))
            && ($actor->system_role === UserSystemRoleEnum::SUPERADMIN
                || $checker->allows($actor, $permission))
        ));
        foreach (array_merge($creation, $privileged ?? []) as $key) {
            $permission = Permission::from($key);
            if (! in_array($permission, $scope, true)
                && ! (! $overrideInherited && $profiles->inherited($target->canonical(), $permission))) {
                throw new AuthorizationException('Нельзя назначать недоступные права.');
            }
        }
        $allowed = array_values(array_filter(array_map(
            static fn (string $value): Permission => Permission::from($value),
            array_merge($creation, $privileged ?? [])
        ), static fn (Permission $permission): bool => in_array($permission, $scope, true)));

        app(AdminUpdateUserOperationalPermissionsHandler::class)
            ->handle($actor, $target->canonical()->id, $allowed, $scope);
    }

    /** @param list<string> $selected
     *  @param list<Permission> $scope
     */
    private function validateSet(array $selected, array $scope, string $field): void
    {
        $keys = array_map(static fn (Permission $p): string => $p->value, $scope);
        if (count($selected) !== count(array_unique($selected))
            || array_filter($selected, 'is_string') !== $selected
            || array_diff($selected, $keys) !== []) {
            throw ValidationException::withMessages([
                $field => 'Выбраны неизвестные или повторяющиеся разрешения.',
            ]);
        }
    }
}
