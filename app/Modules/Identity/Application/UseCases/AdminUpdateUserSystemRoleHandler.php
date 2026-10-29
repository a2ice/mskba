<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Application\Services\StaffPermissionProfiles;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use App\Modules\Notification\Application\Services\RequestThreadMembershipManager;
use App\Modules\Notification\Application\Services\SupportTicketAccess;
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

            $hadOperator = $this->permissionChecker->allows($target, UserOperationalPermissionEnum::OPERATOR_CONVERSATION_HANDLE);
            $hadSupport = $this->permissionChecker->allows($target, UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS);
            $hadProfileReview = $this->permissionChecker->allows($target, UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES);
            $wasBelowReviewRole = ! $target->system_role->atLeast(UserSystemRoleEnum::ADMIN);
            if ($target->system_role === UserSystemRoleEnum::MANAGER && $role !== UserSystemRoleEnum::MANAGER) {
                // Stale specialist grants must not reactivate upon a later promotion.
                $target->managerSpecializations()->detach();
                $target->forceFill(['staff_title' => null])->save();
            }
            $target->update(['system_role' => $role]);
            if ($wasBelowReviewRole && $role->atLeast(UserSystemRoleEnum::ADMIN)) {
                // Previous demotion writes an explicit false reviewer tombstone.
                // Promotion creates a new admin baseline, never resurrects old
                // threads: a fresh review cursor is issued below.
                $target->operationalPermissions()
                    ->where('permission', UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES->value)
                    ->where('is_allowed', false)->delete();
            }
            // Do not allow stale personal grants to reactivate after a user
            // is demoted and later promoted back into the staff hierarchy.
            $profiles = app(StaffPermissionProfiles::class);
            foreach ($target->operationalPermissions()->where('is_allowed', true)->get() as $snapshot) {
                if (! $profiles->allowedForRole($role, $snapshot->permission)) {
                    $snapshot->update(['is_allowed' => false]);
                }
            }
            if ($hadOperator && ! $this->permissionChecker->allows($target->fresh(), UserOperationalPermissionEnum::OPERATOR_CONVERSATION_HANDLE)) {
                app(\App\Modules\Notification\Application\Services\OperatorConversationRevocation::class)
                    ->revokeForUser($target);
            }
            $hasProfileReview = $this->permissionChecker->allows($target->fresh(), UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES);
            if (! $hadSupport && $this->permissionChecker->allows($target->fresh(), UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS)) {
                app(SupportTicketAccess::class)->setGrantCursor($target);
            }
            if ($hadSupport && ! $this->permissionChecker->allows($target->fresh(), UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS)) {
                app(SupportTicketAccess::class)->revokeForUser($target, $lockedActor);
            }
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
            || in_array($role, [UserSystemRoleEnum::SYSTEM, UserSystemRoleEnum::SUPERADMIN,
                UserSystemRoleEnum::MODERATOR, UserSystemRoleEnum::EDITOR], true)
            || $role->numericValue() >= $actorRole->numericValue()
            || ! $this->permissionChecker->allows($actor, UserOperationalPermissionEnum::MANAGE_SYSTEM_ROLES)
        ) {
            throw new AuthorizationException('Недостаточно прав для изменения системной роли пользователя.');
        }
    }
}
