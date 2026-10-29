<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Edit ONLY backoffice permissions, never touch support/venue/creation rights. */
final class BackofficePermissionManager
{
    public function __construct(private readonly UserOperationalPermissionChecker $checker) {}

    public function allows(User $user, Permission $permission): bool
    {
        if (! $permission->isBackoffice()) {
            return false;
        }

        return $this->checker->allows($user, $permission);
    }

    /** @return list<string> */
    /** @return list<string> */
    public function granted(User $user): array
    {
        return array_values(array_map(
            static fn (Permission $p): string => $p->value,
            array_filter(Permission::backofficeCases(),
                fn (Permission $p): bool => $this->checker->allows($user, $p))
        ));
    }

    public function mayManage(User $actor, ?User $target = null): bool
    {
        $actor = $actor->canonical();

        if (! $actor->isConfirmed() || $actor->isBlocked() || $actor->trashed()
            || ! $actor->system_role->atLeast(UserSystemRoleEnum::ADMIN)) {
            return false;
        }

        if ($target === null) {
            return true;
        }

        $target = $target->canonical();

        return $actor->id !== $target->id
            && $target->system_role !== UserSystemRoleEnum::SYSTEM
            && $actor->system_role->numericValue() > $target->system_role->numericValue();
    }

    /**
     * @param list<string> $values
     */
    public function sync(User $actor, User $target, array $values): void
    {
        if (! $this->mayManage($actor, $target)) {
            throw new AuthorizationException('Недостаточно прав на управление доступом Filament.');
        }

        $allowedKeys = array_map(fn (Permission $p): string => $p->value, Permission::backofficeCases());
        if (array_diff($values, $allowedKeys) !== [] || count($values) !== count(array_unique($values))) {
            throw ValidationException::withMessages(['data.backoffice_permissions' => 'Неизвестные или повторяющиеся права.']);
        }

        DB::transaction(function () use ($actor, $target, $values): void {
            $target = User::query()->whereKey($target->canonical()->id)
                ->lockForUpdate()->firstOrFail();
            if (! $this->mayManage($actor, $target)) {
                throw new AuthorizationException('Нельзя изменить права этого пользователя.');
            }

            foreach (Permission::backofficeCases() as $permission) {
                $enabled = in_array($permission->value, $values, true);
                // Admins may neither grant nor revoke a permission absent
                // from their own effective set, even if a crafted Livewire
                // payload omits all disabled options.
                if ($actor->system_role !== UserSystemRoleEnum::SUPERADMIN
                    && ! $this->checker->allows($actor, $permission)) {
                    continue;
                }
                $profiles = app(StaffPermissionProfiles::class);
                if (! $profiles->allowedForRole($target->system_role, $permission)) {
                    if ($enabled) {
                        throw new AuthorizationException('Право недопустимо для роли пользователя.');
                    }
                    continue;
                }
                if ($enabled && ! $this->checker->allows($target, $permission)
                    && $actor->system_role !== UserSystemRoleEnum::SUPERADMIN
                    && ! $this->checker->allows($actor, $permission)) {
                    throw new AuthorizationException('Нельзя передать право, которого нет у администратора.');
                }
                $existing = UserOperationalPermission::query()->where('user_id', $target->id)
                    ->where('permission', $permission->value)->first();
                if ($existing !== null) {
                    if ((bool) $existing->is_allowed !== $enabled) {
                        $existing->update(['is_allowed' => $enabled]);
                    }
                } elseif ($enabled !== $profiles->inherited($target, $permission)
                    && $enabled !== $permission->defaultAllowedFor($target->system_role)) {
                    UserOperationalPermission::query()->create([
                        'user_id' => $target->id,
                        'permission' => $permission->value,
                        'is_allowed' => $enabled,
                    ]);
                }
            }
        });
    }
}
