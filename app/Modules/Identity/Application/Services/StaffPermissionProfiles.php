<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\SystemRolePermissionProfile;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Role/specialization permission defaults and a hard system-role permission ceiling.
 * The effective checker allows audited individual overrides of ordinary defaults.
 * Missing tables during a rolling deployment retain legacy enum defaults.
 */
final class StaffPermissionProfiles
{
    /** @return list<string> */
    public function roleKeys(Role $role): array
    {
        if ($role === Role::SUPERADMIN) {
            return array_map(static fn (Permission $p): string => $p->value, Permission::cases());
        }

        if (! Schema::hasTable('system_role_permission_profiles')) {
            return array_values(array_map(static fn (Permission $p): string => $p->value,
                array_filter(Permission::cases(), static fn (Permission $p): bool => $p->defaultAllowedFor($role))
            ));
        }

        $profile = SystemRolePermissionProfile::query()->find($role->value);

        if ($profile !== null) {
            return array_values(array_filter((array) $profile->permissions, 'is_string'));
        }

        // Old role values are still accepted for historic tests and data reads.
        return array_values(array_map(static fn (Permission $p): string => $p->value,
            array_filter(Permission::cases(), static fn (Permission $p): bool => $p->defaultAllowedFor($role))
        ));
    }

    /** @return list<string> */
    public function specializationKeys(User $user): array
    {
        if ($user->system_role !== Role::MANAGER || ! Schema::hasTable('manager_specializations')) {
            return [];
        }

        return $user->managerSpecializations()->get(['manager_specializations.permissions'])
            ->flatMap(static fn ($item): array => (array) $item->permissions)
            ->filter(static fn ($key): bool => is_string($key))
            ->unique()->values()->all();
    }

    public function inherited(User $user, Permission $permission): bool
    {
        // Legacy role defaults were historically overridable. Preserve their
        // explicit denials until every old role reference has been retired.
        if (in_array($user->system_role, [Role::MODERATOR, Role::EDITOR], true)) {
            return false;
        }

        return in_array($permission->value, $this->roleKeys($user->system_role), true)
            || in_array($permission->value, $this->specializationKeys($user), true);
    }

    /**
     * Grants whose historical access requires a cursor must be assigned through
     * the dedicated audited individual workflow, never via a mutable template.
     */
    public function allowedInProfile(Role $role, Permission $permission): bool
    {
        // Admin reviewer is an explicitly approved baseline with an
        // incremental cursor assigned at migration/promotion. Support tickets
        // still require a separate individual-grant cursor.
        if ($role === Role::ADMIN && $permission === Permission::REVIEW_PROFILE_CHANGES) {
            return true;
        }

        return $this->allowedForRole($role, $permission)
            && ! in_array($permission, [
                Permission::WALLET_USER_BALANCE_CREDIT,
                Permission::HANDLE_SUPPORT_TICKETS, Permission::REVIEW_PROFILE_CHANGES,
            ], true);
    }

    /** Explicit upper bound: a functional manager cannot become a system admin. */
    public function allowedForRole(Role $role, Permission $permission): bool
    {
        if ($permission === Permission::WALLET_USER_BALANCE_CREDIT) {
            return in_array($role, [Role::SUPERADMIN, Role::ADMIN], true);
        }
        if ($role === Role::SUPERADMIN || $role === Role::ADMIN) {
            return true;
        }

        if (in_array($role, [Role::MODERATOR, Role::EDITOR], true)) {
            // Legacy records remain compatible until their data migration.
            return true;
        }

        if ($role === Role::MANAGER) {
            return in_array($permission, [
                Permission::CONTENT_MANAGE,
                Permission::CREATE_COORDINATION, Permission::CREATE_TEAM,
                Permission::CREATE_EVENT, Permission::CREATE_TOURNAMENT,
                Permission::HANDLE_SUPPORT_TICKETS,
                Permission::OPERATOR_PRESENCE_VIEW, Permission::OPERATOR_SESSION_HISTORY_VIEW,
                Permission::OPERATOR_CONVERSATION_HANDLE, Permission::OPERATOR_MARKETING_SEND,
                Permission::BACKOFFICE_ACCESS, Permission::BACKOFFICE_WIDGET_VIEW,
                Permission::BACKOFFICE_WIDGET_CREATE, Permission::BACKOFFICE_WIDGET_EDIT,
                Permission::BACKOFFICE_STYLE_MANAGE, Permission::BACKOFFICE_VENUE_MANAGE,
                Permission::BACKOFFICE_VENUE_MODERATE, Permission::BACKOFFICE_EVENT_MANAGE,
                Permission::BACKOFFICE_TEAM_MANAGE,
                Permission::BACKOFFICE_STYLE_VIEW, Permission::BACKOFFICE_THEME_VIEW,
                Permission::BACKOFFICE_BUILDER_VIEW,
                Permission::BACKOFFICE_GRAPHQL_EXPLORER_VIEW,
                Permission::BACKOFFICE_REST_API_EXPLORER_VIEW,
            ], true);
        }

        if ($role === Role::USER) {
            return ! $permission->isBackoffice()
                && ! in_array($permission, [
                    Permission::CONTENT_MANAGE, Permission::MANAGE_SYSTEM_ROLES,
                    Permission::REVIEW_PROFILE_CHANGES, Permission::HANDLE_SUPPORT_TICKETS,
                    Permission::OPERATOR_PRESENCE_VIEW, Permission::OPERATOR_SESSION_HISTORY_VIEW,
                    Permission::OPERATOR_CONVERSATION_HANDLE, Permission::OPERATOR_MARKETING_SEND,
                ], true);
        }

        return false;
    }
}