<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\ManagerSpecialization;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StaffSpecializationManager
{
    public function mayManage(User $actor, User $target): bool
    {
        $actor = $actor->canonical();
        $target = $target->canonical();

        return $actor->isConfirmed() && ! $actor->isBlocked() && ! $actor->trashed()
            && $actor->system_role->atLeast(Role::ADMIN)
            && $actor->id !== $target->id
            && $actor->system_role->numericValue() > $target->system_role->numericValue()
            && $target->system_role === Role::MANAGER;
    }

    /** @param list<int|string> $ids */
    public function sync(User $actor, User $target, array $ids, ?string $staffTitle = null): void
    {
        if (! $this->mayManage($actor, $target)) {
            throw new AuthorizationException('Недостаточно прав на назначение специализаций.');
        }

        $values = array_map(static function ($value): int {
            if ((! is_int($value) && ! (is_string($value) && ctype_digit($value))) || (int) $value <= 0) {
                throw ValidationException::withMessages(['specialization_ids' => 'Некорректная специализация.']);
            }
            return (int) $value;
        }, $ids);
        if (count($values) !== count(array_unique($values))) {
            throw ValidationException::withMessages(['specialization_ids' => 'Специализации не должны повторяться.']);
        }

        if ($staffTitle !== null && mb_strlen(trim($staffTitle)) > 120) {
            throw ValidationException::withMessages(['staff_title' => 'Название должности слишком длинное.']);
        }

        DB::transaction(function () use ($actor, $target, $values, $staffTitle): void {
            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $lockedTarget = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            if (! $this->mayManage($lockedActor, $lockedTarget)) {
                throw new AuthorizationException('Изменились полномочия или системная роль.');
            }

            $specializations = ManagerSpecialization::query()->whereIn('id', $values)->get();
            if ($specializations->count() !== count($values)) {
                throw ValidationException::withMessages(['specialization_ids' => 'Неизвестная специализация.']);
            }

            $checker = app(UserOperationalPermissionChecker::class);
            $hadConversations = $checker->allows($lockedTarget, Permission::OPERATOR_CONVERSATION_HANDLE);
            $previous = $lockedTarget->managerSpecializations()->pluck('manager_specializations.id')->map(fn ($id): int => (int) $id)->all();
            $newIds = array_diff($values, $previous);
            $ceilings = app(StaffPermissionProfiles::class);
            foreach ($specializations as $specialization) {
                foreach ((array) $specialization->permissions as $key) {
                    $permission = is_string($key) ? Permission::tryFrom($key) : null;
                    if ($permission === null || ! $ceilings->allowedInProfile(Role::MANAGER, $permission)) {
                        throw new AuthorizationException('Специализация содержит запрещённые права.');
                    }
                    if (in_array($specialization->id, $newIds, true)
                        && $lockedActor->system_role !== Role::SUPERADMIN
                        && ! $checker->allows($lockedActor, $permission)) {
                        throw new AuthorizationException('Нельзя передать право, отсутствующее у назначающего.');
                    }
                }
            }

            $lockedTarget->managerSpecializations()->sync($values);
            if ($hadConversations && ! $checker->allows($lockedTarget->fresh(), Permission::OPERATOR_CONVERSATION_HANDLE)) {
                app(\App\Modules\Notification\Application\Services\OperatorConversationRevocation::class)
                    ->revokeForUser($lockedTarget);
            }
            if ($staffTitle !== null) {
                $lockedTarget->forceFill(['staff_title' => trim($staffTitle) ?: null])->save();
            }
        });
    }
}