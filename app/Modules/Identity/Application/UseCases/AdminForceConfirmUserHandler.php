<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\GlobalOperationalPermissionManager;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminForceConfirmUserHandler
{
    public function canConfirm(?User $actor, User $target): bool
    {
        $actor = $actor?->canonical();
        $target = $target->canonical();

        return $actor !== null
            && $actor->system_role === UserSystemRoleEnum::SUPERADMIN
            && app(GlobalOperationalPermissionManager::class)->mayManage($actor, $target)
            && app(UserOperationalPermissionChecker::class)->allows($actor, Permission::BACKOFFICE_ACCESS)
            && ! $target->trashed()
            && $target->system_role === UserSystemRoleEnum::USER
            && $target->status === UserStatusEnum::UNCONFIRMED;
    }

    public function handle(User $actor, User $target, string $reason): User
    {
        if (! $this->canConfirm($actor, $target)) {
            throw new AuthorizationException('Принудительное подтверждение недоступно.');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => 'Укажите основание подтверждения (от 10 до 500 символов).',
            ]);
        }

        return DB::transaction(function () use ($actor, $target, $reason): User {
            $actor = $actor->canonical()->fresh();
            $canonicalId = (int) $target->canonical()->id;
            $locked = User::query()->whereKey($canonicalId)->lockForUpdate()->firstOrFail();

            if ($locked->canonical_user_id !== null
                || (int) $target->fresh()?->canonical()->id !== $canonicalId
                || ! $this->canConfirm($actor, $locked)) {
                throw new AuthorizationException('Принудительное подтверждение недоступно.');
            }

            $missing = $locked->missingAccountConfirmationRequirements();

            if ($missing === []) {
                throw ValidationException::withMessages([
                    'reason' => 'Все условия выполнены. Используйте обычное подтверждение.',
                ]);
            }

            // Administrative exception only. Do not manufacture verification
            // evidence, mutate profile fields, grant permissions or emit the
            // normal self-confirmation reward event.
            $locked->forceFill(['status' => UserStatusEnum::CONFIRMED])->save();

            AuditLog::query()->create([
                'actor_id' => app(CurrentActorResolver::class)->resolve($actor, null)?->id,
                'auditable_type' => User::class,
                'auditable_id' => $locked->id,
                'event' => 'admin_user_force_confirmed',
                'old_values' => ['status' => UserStatusEnum::UNCONFIRMED->value],
                'new_values' => ['status' => UserStatusEnum::CONFIRMED->value],
                'metadata' => [
                    'reason' => $reason,
                    'missing_requirements' => $missing,
                    'actor_user_id' => $actor->id,
                ],
            ]);

            return $locked->refresh();
        }, 3);
    }
}
