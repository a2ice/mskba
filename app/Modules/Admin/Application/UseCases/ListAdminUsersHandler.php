<?php

namespace App\Modules\Admin\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAdminUsersHandler
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = User::query()->with('profile')->latest('id');

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

        return $query
            ->paginate($this->perPage($filters))
            ->withQueryString();
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
