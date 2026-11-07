<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminRevertUserSystemRoleHandler
{
    public function handle(User $actor, User $target, int $revisionId, string $reason): AuditLog
    {
        $actor = $actor->canonical();
        $target = $target->canonical();
        app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);

        return DB::transaction(function () use ($actor, $target, $revisionId, $reason): AuditLog {
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
            $revision = AuditLog::query()->whereKey($revisionId)
                ->where('auditable_type', User::class)
                ->where('auditable_id', $target->id)
                ->where('event', 'admin_system_role_changed')
                ->lockForUpdate()->firstOrFail();
            $before = $this->normalizeSnapshot((array) $revision->old_values);
            $after = $this->normalizeSnapshot((array) $revision->new_values);
            $editor = app(AdminChangeUserSystemRoleWithAuditHandler::class);
            if ($editor->snapshot($target) !== $after) {
                throw ValidationException::withMessages([
                    'revision' => 'После изменения роли менялись разрешения или специализации. Откат запрещён.',
                ]);
            }

            $role = is_string($before['role'] ?? null)
                ? UserSystemRoleEnum::tryFrom($before['role']) : null;
            if ($role === null) {
                throw ValidationException::withMessages(['revision' => 'Неизвестная исходная роль.']);
            }
            // Delegate to the original safe role transition. Never restore
            // explicit grants or detached specializations by direct writes.
            // If the previous complete state cannot be reconstructed without
            // that, roll back this transaction and require a manual grant.
            $result = $editor->handle($actor, $target, $role, $reason, $revision->id);
            if ($result === null || $editor->snapshot($target->fresh()) !== $before) {
                throw ValidationException::withMessages([
                    'revision' => 'Автоматический откат затронул бы отозванные права. Восстановите роль и разрешения отдельно.',
                ]);
            }

            return $result;
        });
    }

    private function normalizeSnapshot(array $snapshot): array
    {
        $grants = $snapshot['explicit_grants'] ?? null;
        if (is_array($grants) && array_key_exists('backoffice.system_access', $grants)) {
            if (($snapshot['role'] ?? null) === 'system') {
                $grants['backoffice.access'] = $grants['backoffice.system_access'];
            }
            unset($grants['backoffice.system_access']);
            ksort($grants);
            $snapshot['explicit_grants'] = $grants;
        }

        return $snapshot;
    }
}
