<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminUpdateUserPermissionSnapshotHandler
{
    /** @return array{role: string, permissions: list<string>} */
    public function snapshot(User $user): array
    {
        $global = app(GlobalOperationalPermissionManager::class);
        $all = array_merge($global->creationPermissions(), $global->privilegedPermissions(), Permission::backofficeCases());
        $checker = app(UserOperationalPermissionChecker::class);
        $keys = array_values(array_map(
            static fn (Permission $permission): string => $permission->value,
            array_filter($all, fn (Permission $permission): bool => $checker->allows($user, $permission)),
        ));
        sort($keys);

        return ['role' => $user->system_role->value, 'permissions' => $keys];
    }

    /**
     * @param list<string> $creation
     * @param list<string> $privileged
     * @param list<string> $backoffice
     */
    public function handle(
        User $actor,
        User $target,
        array $creation,
        array $privileged,
        array $backoffice,
        string $reason,
        ?int $revertedRevisionId = null,
    ): ?AuditLog {
        foreach (['creation' => $creation, 'privileged' => $privileged, 'backoffice' => $backoffice] as $field => $keys) {
            if (array_filter($keys, 'is_string') !== $keys || count($keys) !== count(array_unique($keys))) {
                throw ValidationException::withMessages([
                    $field => 'Некорректный или повторяющийся набор разрешений.',
                ]);
            }
        }
        $actor = $actor->canonical();
        $target = $target->canonical();
        app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['change_reason' => 'Укажите основание изменения прав (10–500 символов).']);
        }

        return DB::transaction(function () use (
            $actor, $target, $creation, $privileged, $backoffice, $reason, $revertedRevisionId,
        ): ?AuditLog {
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
            $before = $this->snapshot($target);
            app(GlobalOperationalPermissionManager::class)->sync($actor, $target, $creation, $privileged, true);
            app(BackofficePermissionManager::class)->sync($actor, $target, $backoffice, true);
            $after = $this->snapshot($target->fresh());
            if ($before === $after) {
                return null;
            }

            return AuditLog::query()->create([
                'actor_id' => app(CurrentActorResolver::class)->resolveForRequest(request())?->id,
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'event' => $revertedRevisionId === null
                    ? 'admin_user_permissions_changed' : 'admin_user_permissions_reverted',
                'old_values' => $before,
                'new_values' => $after,
                'metadata' => array_filter([
                    'reason' => $reason,
                    'target_user_id' => $target->id,
                    'reverted_revision_id' => $revertedRevisionId,
                ], static fn ($value) => $value !== null),
            ]);
        });
    }
}
