<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Application\Services\StaffPermissionProfiles;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use App\Modules\Notification\Application\Services\RequestThreadMembershipManager;
use App\Modules\Notification\Application\Services\SupportTicketAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class AdminUpdateUserOperationalPermissionsHandler
{
    /**
     * @param  array<UserOperationalPermissionEnum>  $allowedPermissions
     */
    public function handle(User $actor, int $targetUserId, array $allowedPermissions, ?array $scope = null): void
    {
        $actor = $actor->canonical();

        DB::transaction(function () use ($actor, $targetUserId, $allowedPermissions, $scope): void {
            $requested = User::query()->findOrFail($targetUserId);
            $target = User::query()
                ->whereKey($requested->canonical()->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->authorize($lockedActor, $target);
            $profiles = app(StaffPermissionProfiles::class);
            $checker = app(UserOperationalPermissionChecker::class);

            if (! $target->system_role->atLeast(UserSystemRoleEnum::ADMIN)
                && in_array(UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES, $allowedPermissions, true)) {
                throw new AuthorizationException('Это право доступно только администраторам.');
            }

            // The historical bulk form covers all non-Filament permissions.
            // Scoped calls from the unified editor update only the selected
            // global groups, never unrelated rights or grant cursors.
            if ($scope !== null) {
                foreach ($scope as $candidate) {
                    if (! $candidate instanceof UserOperationalPermissionEnum || $candidate->isBackoffice()) {
                        throw new AuthorizationException('Некорректная область изменения разрешений.');
                    }
                }
                foreach ($allowedPermissions as $candidate) {
                    if (! in_array($candidate, $scope, true)) {
                        throw new AuthorizationException('Нельзя назначить право вне выбранной области.');
                    }
                }
            }

            $allowed = collect($allowedPermissions)
                ->map(fn (UserOperationalPermissionEnum $permission): string => $permission->value)
                ->flip();

            foreach (UserOperationalPermissionEnum::cases() as $permission) {
                // Legacy admins may manage ordinary operational rights, but must
                // never grant or revoke Filament privileges, including by omitting
                // fields from an HTML form.
                if ($permission->isBackoffice() || ($scope !== null && ! in_array($permission, $scope, true))) {
                    // Backoffice rights are managed ONLY through the dedicated
                    // Filament form. Saving old permission forms must not
                    // accidentally revoke a previous Filament grant.
                    continue;
                }
                if ($actor->system_role !== UserSystemRoleEnum::SUPERADMIN
                    && in_array($permission, [
                        UserOperationalPermissionEnum::MANAGE_SYSTEM_ROLES,
                        UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES,
                        UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS,
                        UserOperationalPermissionEnum::OPERATOR_PRESENCE_VIEW,
                        UserOperationalPermissionEnum::OPERATOR_SESSION_HISTORY_VIEW,
                        UserOperationalPermissionEnum::OPERATOR_CONVERSATION_HANDLE,
                        UserOperationalPermissionEnum::OPERATOR_MARKETING_SEND,
                    ], true) && ($scope === null
                        || ! app(\App\Modules\Identity\Application\Services\UserOperationalPermissionChecker::class)->allows($actor, $permission))) {
                    // Legacy bulk forms remain conservative. Unified Filament
                    // forms delegate only rights already held by the actor.
                    continue;
                }
                $enabled = $allowed->has($permission->value);
                // Legacy HTML endpoints use the same immutable role baseline,
                // role ceiling, and actor delegation rules as Filament.
                if (! $profiles->allowedForRole($target->system_role, $permission)) {
                    if ($enabled) {
                        throw new AuthorizationException('Право недопустимо для роли получателя.');
                    }
                    continue;
                }
                if ($lockedActor->system_role !== UserSystemRoleEnum::SUPERADMIN
                    && ! $checker->allows($lockedActor, $permission)) {
                    if ($enabled) {
                        throw new AuthorizationException('Нельзя выдать отсутствующее у администратора право.');
                    }
                    continue;
                }
                $wasEffective = $checker->allows($target, $permission);
                $current = UserOperationalPermission::query()
                    ->where('user_id', $target->id)->where('permission', $permission->value)->first();
                // Capture the previous effective value *before* updating the Eloquent model.
                // A regrant must establish a new history boundary, not revive the old one.
                $wasEnabled = $wasEffective;
                if ($current !== null) {
                    if ((bool) $current->is_allowed !== $enabled) {
                        $current->update(['is_allowed' => $enabled]);
                    }
                } elseif ($enabled !== ($profiles->inherited($target, $permission)
                    || (in_array($target->system_role, [UserSystemRoleEnum::MODERATOR, UserSystemRoleEnum::EDITOR], true)
                        && $permission->defaultAllowedFor($target->system_role)))) {
                    UserOperationalPermission::query()->create([
                        'user_id' => $target->id, 'permission' => $permission->value,
                        'is_allowed' => $enabled,
                    ]);
                }
                $isEffective = $checker->allows($target, $permission);
                if ($permission === UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES && $isEffective && ! $wasEnabled) {
                    DB::table('review_permission_cursors')->updateOrInsert(
                        ['user_id' => $target->id, 'permission' => $permission->value],
                        ['after_request_id' => ProfileChangeRequest::query()->max('id') ?? 0,
                            'created_at' => now(), 'updated_at' => now()]);
                }
                if ($permission === UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES && ! $isEffective && $wasEnabled) {
                    // Preserve a tombstone, but never allow a future regrant to reactivate
                    // an old membership silently. Subquery avoids a large permission JOIN.
                    app(RequestThreadMembershipManager::class)
                        ->revokeForUser($target, $actor);
                }
                if ($permission === UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS && $isEffective && ! $wasEnabled) {
                    app(SupportTicketAccess::class)->setGrantCursor($target);
                }
                // Revoking the permission instantly blocks history, POST and
                // private websocket authorization. Old explicit memberships do
                // not override the checker on future regrants.
                if ($permission === UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS && ! $isEffective && $wasEnabled) {
                    app(SupportTicketAccess::class)->revokeForUser($target, $actor);
                }
                if ($permission === UserOperationalPermissionEnum::OPERATOR_CONVERSATION_HANDLE
                    && ! $isEffective && $wasEnabled) {
                    app(\App\Modules\Notification\Application\Services\OperatorConversationRevocation::class)
                        ->revokeForUser($target);
                }
                // Unchanged values must not reset the original grant timestamp.
            }
        });
    }

    private function authorize(User $actor, User $target): void
    {
        $actorRole = $actor->system_role;
        $targetRole = $target->system_role;

        if (
            ! $actor->isConfirmed()
            || ! $actorRole->atLeast(UserSystemRoleEnum::ADMIN)
            || $actor->id === $target->id
            || $actorRole->numericValue() <= $targetRole->numericValue()
        ) {
            throw new AuthorizationException('Недостаточно прав для изменения операционных прав пользователя.');
        }
    }
}
