<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminRevertParticipationRoleHandler
{
    public function handle(User $actor, User $target, int $revisionId, string $reason): AuditLog
    {
        $actor = $actor->canonical();
        $target = $target->canonical();
        app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите основание отката (10–500 символов).']);
        }

        return DB::transaction(function () use ($actor, $target, $revisionId, $reason): AuditLog {
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
            $revision = AuditLog::query()
                ->whereKey($revisionId)
                ->where('auditable_type', User::class)
                ->where('auditable_id', $target->id)
                ->where('event', 'admin_participation_role_changed')
                ->lockForUpdate()->firstOrFail();
            $before = (array) $revision->old_values;
            $after = (array) $revision->new_values;
            if (array_keys($before) !== ['status', 'comment'] || array_keys($after) !== ['status', 'comment']
                || ! UserParticipationRoleStatusEnum::tryFrom((string) ($before['status'] ?? ''))) {
                throw ValidationException::withMessages(['revision' => 'Недопустимая ревизия роли участия.']);
            }

            $role = $target->participationRoles(false)
                ->whereKey((int) ($revision->metadata['participation_role_id'] ?? 0))
                ->lockForUpdate()->firstOrFail();
            if ($role->role->value !== ($revision->metadata['participation_role'] ?? null)) {
                throw ValidationException::withMessages(['revision' => 'Роль была заменена.']);
            }
            $current = ['status' => $role->getRawOriginal('status'), 'comment' => $role->getRawOriginal('comment')];
            if ($current !== $after) {
                throw ValidationException::withMessages(['revision' => 'После этой ревизии статус или комментарий уже изменился.']);
            }
            $role->forceFill($before)->save();

            return AuditLog::query()->create([
                'actor_id' => app(CurrentActorResolver::class)->resolveForRequest(request())?->id,
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'event' => 'admin_participation_role_reverted',
                'old_values' => $after,
                'new_values' => $before,
                'metadata' => [
                    'reason' => $reason,
                    'reverted_revision_id' => $revision->id,
                    'participation_role_id' => $role->id,
                    'participation_role' => $role->role->value,
                ],
            ]);
        });
    }
}
