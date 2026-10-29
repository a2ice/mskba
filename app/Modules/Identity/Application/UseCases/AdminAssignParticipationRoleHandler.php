<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminAssignParticipationRoleHandler
{
    public function handle(User $actor, User $target, string $roleValue, string $reason): AuditLog
    {
        $actor = $actor->canonical();
        $target = $target->canonical();
        app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
        $role = UserParticipationRoleEnum::tryFrom($roleValue);
        if ($role === null) {
            throw ValidationException::withMessages(['role' => 'Неизвестная роль участия.']);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите основание назначения (10–500 символов).']);
        }

        return DB::transaction(function () use ($actor, $target, $role, $reason): AuditLog {
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
            if ($target->participationRoles(false)->where('role', $role->value)->exists()) {
                throw ValidationException::withMessages(['role' => 'Роль уже назначена. Для отключённой роли используйте восстановление.']);
            }

            $assignment = $target->participationRoles(false)->create([
                'role' => $role,
                'status' => UserParticipationRoleStatusEnum::ACTIVE,
                'assigned_at' => now(),
                'assigned_by' => $actor->id,
                'assigner' => UserParticipationRoleAssignerEnum::USER,
            ]);
            if ($role === UserParticipationRoleEnum::PLAYER && ! $target->playerProfile()->exists()) {
                $target->playerProfile()->create([]);
            }

            return AuditLog::query()->create([
                'actor_id' => app(CurrentActorResolver::class)->resolveForRequest(request())?->id,
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'event' => 'admin_participation_role_added',
                'old_values' => [],
                'new_values' => ['role' => $role->value, 'status' => UserParticipationRoleStatusEnum::ACTIVE->value],
                'metadata' => [
                    'reason' => $reason,
                    'participation_role_id' => $assignment->id,
                    'participation_role' => $role->value,
                ],
            ]);
        });
    }
}
