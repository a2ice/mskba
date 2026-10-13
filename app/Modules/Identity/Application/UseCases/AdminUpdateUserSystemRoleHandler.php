<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use App\Modules\Notification\Application\Services\RequestThreadMembershipManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class AdminUpdateUserSystemRoleHandler
{
    public function __construct(
        private readonly UserOperationalPermissionChecker $permissionChecker,
    ) {}

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

            $hadProfileReview = $this->permissionChecker->allows($target, UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES);
            $target->update(['system_role' => $role]);
            $hasProfileReview = $this->permissionChecker->allows($target->fresh(), UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES);
            if (! $hadProfileReview && $hasProfileReview) {
                // Role promotion is a new grant; never expose older requests.
                DB::table('review_permission_cursors')->updateOrInsert(
                    ['user_id' => $target->id, 'permission' => UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES->value],
                    ['after_request_id' => ProfileChangeRequest::query()->max('id') ?? 0,
                        'created_at' => now(), 'updated_at' => now()],
                );
            }
            if ($hadProfileReview && ! $hasProfileReview) {
                UserOperationalPermission::query()->updateOrCreate(
                    ['user_id' => $target->id, 'permission' => UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES->value],
                    ['is_allowed' => false],
                );
                app(RequestThreadMembershipManager::class)
                    ->revokeForUser($target, $lockedActor);
            }
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
