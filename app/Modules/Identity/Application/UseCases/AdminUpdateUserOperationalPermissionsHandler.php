<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
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

            $allowed = collect($allowedPermissions)
                ->map(fn (UserOperationalPermissionEnum $permission): string => $permission->value)
                ->flip();

            foreach (UserOperationalPermissionEnum::cases() as $permission) {
                UserOperationalPermission::query()->updateOrCreate(
                    [
                        'user_id' => $target->id,
                        'permission' => $permission->value,
                    ],
                    [
                        'is_allowed' => $allowed->has($permission->value),
                    ],
                );
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
