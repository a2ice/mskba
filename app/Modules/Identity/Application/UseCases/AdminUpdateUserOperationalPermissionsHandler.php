<?php

namespace App\Modules\Identity\Application\UseCases;

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
    public function handle(User $actor, int $targetUserId, array $allowedPermissions): void
    {
        $actor = $actor->canonical();

        DB::transaction(function () use ($actor, $targetUserId, $allowedPermissions): void {
            $requested = User::query()->findOrFail($targetUserId);
            $target = User::query()
                ->whereKey($requested->canonical()->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorize($actor, $target);

            if (! $target->system_role->atLeast(UserSystemRoleEnum::ADMIN)
                && in_array(UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES, $allowedPermissions, true)) {
                throw new AuthorizationException('Это право доступно только администраторам.');
            }

            $allowed = collect($allowedPermissions)
                ->map(fn (UserOperationalPermissionEnum $permission): string => $permission->value)
                ->flip();

            foreach (UserOperationalPermissionEnum::cases() as $permission) {
                $enabled = $allowed->has($permission->value);
                $current = UserOperationalPermission::query()
                    ->where('user_id', $target->id)->where('permission', $permission->value)->first();
                // Capture the previous effective value *before* updating the Eloquent model.
                // A regrant must establish a new history boundary, not revive the old one.
                $wasEnabled = $current !== null
                    ? (bool) $current->is_allowed
                    : $permission->defaultAllowedFor($target->system_role);
                if ($current !== null) {
                    if ($wasEnabled !== $enabled) {
                        $current->update(['is_allowed' => $enabled]);
                    }
                } elseif ($enabled !== $permission->defaultAllowedFor($target->system_role)) {
                    UserOperationalPermission::query()->create([
                        'user_id' => $target->id, 'permission' => $permission->value,
                        'is_allowed' => $enabled,
                    ]);
                }
                if ($permission === UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES && $enabled && ! $wasEnabled) {
                    DB::table('review_permission_cursors')->updateOrInsert(
                        ['user_id' => $target->id, 'permission' => $permission->value],
                        ['after_request_id' => ProfileChangeRequest::query()->max('id') ?? 0,
                            'created_at' => now(), 'updated_at' => now()]);
                }
                if ($permission === UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES && ! $enabled && $wasEnabled) {
                    // Preserve a tombstone, but never allow a future regrant to reactivate
                    // an old membership silently. Subquery avoids a large permission JOIN.
                    app(RequestThreadMembershipManager::class)
                        ->revokeForUser($target, $actor);
                }
                if ($permission === UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS && $enabled && ! $wasEnabled) {
                    app(SupportTicketAccess::class)->setGrantCursor($target);
                }
                // Revoking the permission instantly blocks history, POST and
                // private websocket authorization. Old explicit memberships do
                // not override the checker on future regrants.
                if ($permission === UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS && ! $enabled && $wasEnabled) {
                    app(SupportTicketAccess::class)->revokeForUser($target, $actor);
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
