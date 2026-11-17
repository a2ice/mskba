<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminRevertStaffSpecializationsHandler
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
                ->whereKey($revisionId)->where('auditable_type', User::class)
                ->where('auditable_id', $target->id)
                ->where('event', 'admin_staff_specializations_changed')
                ->lockForUpdate()->firstOrFail();
            $editor = app(AdminUpdateStaffSpecializationsWithAuditHandler::class);
            $before = (array) $revision->old_values;
            $after = (array) $revision->new_values;

            if ($editor->snapshot($target) !== $after
                || ! is_array($before['specialization_ids'] ?? null)
                || ! is_string($before['role'] ?? null)
                || $before['role'] !== $target->system_role->value) {
                throw ValidationException::withMessages([
                    'revision' => 'Специализации, должность или роль уже изменились. Откат недоступен.',
                ]);
            }
            $result = $editor->handle(
                $actor, $target, $before['specialization_ids'],
                (string) ($before['staff_title'] ?? ''),
                $reason, $revision->id,
            );
            if ($result === null || $editor->snapshot($target->fresh()) !== $before) {
                throw ValidationException::withMessages(['revision' => 'Не удалось восстановить предыдущее состояние.']);
            }

            return $result;
        });
    }
}
