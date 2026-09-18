<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class AdminUpdateUserSystemRoleHandler
{
    public function __construct(
        private readonly UserOperationalPermissionChecker $permissionChecker,
    ) {
    }

    public function handle(User $actor, int $targetUserId, UserSystemRoleEnum $role): void
    {
        $actor = $actor->canonical();

        DB::transaction(function () use ($actor, $targetUserId, $role): void {
            $lockedActor = User::query()
                ->whereKey($actor->id)
                ->lockForUpdate()
                ->firstOrFail();

            $requested = User::query()->findOrFail($targetUserId);
            $target = User::query()
                ->whereKey($requested->canonical()->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorize($lockedActor, $target, $role);

            if ($target->system_role === $role) {
                return;
            }

            $target->update([
                'system_role' => $role,
            ]);
        });
    }

    private function authorize(User $actor, User $target, UserSystemRoleEnum $role): void
    {
        $actorRole = $actor->system_role;

        if (
            ! $actor->isConfirmed()
            || ! $actorRole->atLeast(UserSystemRoleEnum::ADMIN)
            || $actor->id === $target->id
            || $actorRole->numericValue() <= $target->system_role->numericValue()
            || $role->numericValue() >= $actorRole->numericValue()
            || ! $this->permissionChecker->allows($actor, UserOperationalPermissionEnum::MANAGE_SYSTEM_ROLES)
        ) {
            throw new AuthorizationException('Недостаточно прав для изменения системной роли пользователя.');
        }
    }
}
