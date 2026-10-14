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
    public function granted(User $user): array
    {
        $user = $user->canonical();
        $snapshots = $user->operationalPermissions()
            ->whereIn('permission', array_map(
                fn (Permission $p): string => $p->value, Permission::backofficeCases()
            ))
            ->get()->keyBy(fn (UserOperationalPermission $p): string => $p->permission->value);

        return array_values(array_map(
            fn (Permission $p): string => $p->value,
            array_filter(Permission::backofficeCases(), static function (Permission $p) use ($user, $snapshots): bool {
                if ($user->isBlocked() || $user->trashed() || ! $user->isConfirmed()) {
                    // Show configured/default selections, even if temporarily
                    // inactive due to account status.
                }
                return ($snapshots->get($p->value)?->is_allowed)
                    ?? $p->defaultAllowedFor($user->system_role);
            })
        ));
    }

    public function mayManage(User $actor, ?User $target = null): bool
    {
        $actor = $actor->canonical();

        if (! $actor->isConfirmed() || $actor->isBlocked() || $actor->trashed()
            || $actor->system_role !== UserSystemRoleEnum::SUPERADMIN) {
            return false;
        }

        if ($target === null) {
            return true;
        }

        $target = $target->canonical();

        return $actor->id !== $target->id
            && $target->system_role !== UserSystemRoleEnum::SUPERADMIN
            && $target->system_role !== UserSystemRoleEnum::SYSTEM;
    }

    /**
     * @param list<string> $values
     */
    public function sync(User $actor, User $target, array $values): void
    {
        if (! $this->mayManage($actor, $target)) {
            throw new AuthorizationException('Управление доступом Filament разрешено только суперадминистратору.');
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
                $existing = UserOperationalPermission::query()->where('user_id', $target->id)
                    ->where('permission', $permission->value)->first();
                if ($existing !== null) {
                    if ((bool) $existing->is_allowed !== $enabled) {
                        $existing->update(['is_allowed' => $enabled]);
                    }
                } elseif ($enabled !== $permission->defaultAllowedFor($target->system_role)) {
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
