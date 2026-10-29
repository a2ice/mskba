<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminEditParticipationRoleHandler
{
    public function handle(User $actor, User $target, int $roleId, string $statusKey, ?string $comment, string $reason): AuditLog
    {
        $actor = $actor->canonical();
        $target = $target->canonical();
        app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
        $status = UserParticipationRoleStatusEnum::tryFrom($statusKey);
        if ($status === null) {
            throw ValidationException::withMessages(['status' => 'Неизвестный статус роли участия.']);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите основание изменения (10–500 символов).']);
        }
        $comment = $comment === null ? null : trim($comment);
        if (mb_strlen($comment ?? '') > 2000) {
            throw ValidationException::withMessages(['comment' => 'Комментарий не должен превышать 2000 символов.']);
        }
        $comment = $comment === '' ? null : $comment;

        return DB::transaction(function () use ($actor, $target, $roleId, $status, $comment, $reason): AuditLog {
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
            $role = $target->participationRoles(false)->whereKey($roleId)->lockForUpdate()->firstOrFail();
            $before = ['status' => $role->getRawOriginal('status'), 'comment' => $role->getRawOriginal('comment')];
            $role->forceFill(['status' => $status, 'comment' => $comment])->save();
            $role->refresh();
            $after = ['status' => $role->getRawOriginal('status'), 'comment' => $role->getRawOriginal('comment')];
            if ($before === $after) {
                throw ValidationException::withMessages(['status' => 'Данные роли не изменились.']);
            }

            return AuditLog::query()->create([
                'actor_id' => app(CurrentActorResolver::class)->resolveForRequest(request())?->id,
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'event' => 'admin_participation_role_changed',
                'old_values' => $before,
                'new_values' => $after,
                'metadata' => [
                    'reason' => $reason,
                    'participation_role_id' => $role->id,
                    'participation_role' => $role->role->value,
                ],
            ]);
        });
    }
}
