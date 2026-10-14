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
        ];
    }

    /** @return list<Permission> */
    public function privilegedPermissions(): array
    {
        return [
            Permission::MANAGE_SYSTEM_ROLES, Permission::REVIEW_PROFILE_CHANGES,
            Permission::HANDLE_SUPPORT_TICKETS,
        ];
    }

    /** @return list<string> */
    public function granted(User $target): array
    {
        $target = $target->canonical();
        $snapshots = $target->operationalPermissions()
            ->whereIn('permission', array_map(
                static fn (Permission $p): string => $p->value,
                array_merge($this->creationPermissions(), $this->privilegedPermissions()),
            ))
            ->get()->keyBy(static fn (UserOperationalPermission $p): string => $p->permission->value);

        return array_values(array_map(
            static fn (Permission $p): string => $p->value,
            array_filter(
                array_merge($this->creationPermissions(), $this->privilegedPermissions()),
                static fn (Permission $p): bool =>
                    ($snapshots->get($p->value)?->is_allowed) ?? $p->defaultAllowedFor($target->system_role),
            ),
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
                ? ($p->defaultAllowedFor($target->system_role) ? 'По роли / по умолчанию' : 'Не предоставлено')
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

        return $this->mayManage($actor)
            && $actor->system_role === UserSystemRoleEnum::SUPERADMIN;
    }

    /**
     * @param list<string> $creation
     * @param list<string>|null $privileged null means "leave unchanged".
     */
    public function sync(User $actor, User $target, array $creation, ?array $privileged = null): void
    {
        if (! $this->mayManage($actor, $target)) {
            throw new AuthorizationException('Недостаточно прав для изменения глобальных разрешений пользователя.');
        }

        $this->validateSet($creation, $this->creationPermissions(), 'data.creation_permissions');
        if ($privileged !== null) {
            if (! $this->canManagePrivileged($actor)) {
                throw new AuthorizationException('Критичные глобальные разрешения может назначать только superadmin.');
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

        // Delegation can never amplify the administrator's own effective
        // creation rights, including when superadmin explicitly denied one.
        if (! $this->canManagePrivileged($actor)) {
            $checker = app(UserOperationalPermissionChecker::class);
            foreach ($creation as $selected) {
                if (! $checker->allows($actor, Permission::from($selected))) {
                    throw new AuthorizationException('Нельзя передать право, которого нет у администратора.');
                }
            }
        }

        $allowed = array_map(static fn (string $v): Permission => Permission::from($v),
            array_merge($creation, $privileged ?? []));
        $scope = $privileged === null
            ? $this->creationPermissions()
            : array_merge($this->creationPermissions(), $this->privilegedPermissions());

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
