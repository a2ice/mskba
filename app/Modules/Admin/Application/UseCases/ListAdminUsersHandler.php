<?php

namespace App\Modules\Admin\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAdminUsersHandler
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = User::query()
            ->whereNull('canonical_user_id')
            ->with(['profile', 'operationalPermissions'])
            ->withCount('aliases')
            ->latest('id');

        if (($filters['deleted'] ?? '') === '1') {
            $query->onlyTrashed();
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where('username', 'like', '%'.$search.'%');
        }

        $status = UserStatusEnum::tryFrom((string) ($filters['status'] ?? ''));
        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $role = UserSystemRoleEnum::tryFrom((string) ($filters['role'] ?? ''));
        if ($role !== null) {
            $query->where('system_role', $role->value);
        }

        $paginator = $query
            ->paginate($this->perPage($filters))
            ->withQueryString();

        foreach ($paginator->items() as $user) {
            $snapshot = $user->operationalPermissions
                ->keyBy(fn (UserOperationalPermission $entry): string => $entry->permission->value);

            $effective = collect(UserOperationalPermissionEnum::cases())
                ->map(function (UserOperationalPermissionEnum $permission) use ($snapshot): UserOperationalPermission {
                    return $snapshot->get($permission->value)
                        ?? new UserOperationalPermission([
                            'permission' => $permission,
                            'is_allowed' => $permission->defaultAllowed(),
                        ]);
                });

            $user->setRelation('operationalPermissions', $effective);
        }

        return $paginator;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return min(max($perPage, 5), 50);
    }
}
