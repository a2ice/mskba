<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminRevertUserPermissionSnapshotHandler
{
    public function handle(User $actor, User $target, int $revisionId, string $reason): AuditLog
    {
        $actor = $actor->canonical();
        $target = $target->canonical();
        app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);

        return DB::transaction(function () use ($actor, $target, $revisionId, $reason): AuditLog {
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
            $revision = AuditLog::query()
                ->whereKey($revisionId)
                ->where('auditable_type', User::class)
                ->where('auditable_id', $target->id)
                ->where('event', 'admin_user_permissions_changed')
                ->lockForUpdate()->firstOrFail();

            $editor = app(AdminUpdateUserPermissionSnapshotHandler::class);
            $before = (array) $revision->old_values;
            $after = (array) $revision->new_values;
            $current = $editor->snapshot($target);
            if ($current !== $after) {
                throw ValidationException::withMessages([
                    'revision' => 'Системная роль или разрешения изменились после этой ревизии. Откат запрещён.',
                ]);
            }
            if (! is_array($before['permissions'] ?? null) || ! is_string($before['role'] ?? null)
                || $before['role'] !== $target->system_role->value) {
                throw ValidationException::withMessages(['revision' => 'Ревизия не подходит для отката.']);
            }
            $keys = $before['permissions'];
            $global = app(GlobalOperationalPermissionManager::class);
            $select = static fn (array $cases): array => array_values(array_intersect(
                $keys, array_map(static fn (Permission $p): string => $p->value, $cases),
            ));
            $result = $editor->handle(
                $actor,
                $target,
                $select($global->creationPermissions()),
                $select($global->privilegedPermissions()),
                $select(Permission::backofficeCases()),
                $reason,
                $revision->id,
            );
            if ($result === null || $editor->snapshot($target->fresh()) !== $before) {
                throw ValidationException::withMessages([
                    'revision' => 'Восстановить все права с учётом действующих ограничений не удалось.',
                ]);
            }

            return $result;
        });
    }
}
