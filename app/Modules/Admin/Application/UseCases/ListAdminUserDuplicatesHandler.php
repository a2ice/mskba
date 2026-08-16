<?php

namespace App\Modules\Admin\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum;
use App\Modules\Identity\Domain\Models\UserDuplicate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAdminUserDuplicatesHandler
{
    /** @param array<string, mixed> $filters */
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = UserDuplicate::query()
            ->with([
                'user.profile',
                'user.telegramAccount',
                'duplicateUser.profile',
                'duplicateUser.telegramAccount',
                'evidence',
                'resolvedByUser',
            ])
            ->latest('id');

        $status = UserDuplicateStatusEnum::tryFrom((string) ($filters['status'] ?? ''));
        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return $query
            ->paginate($this->perPage($filters))
            ->withQueryString();
    }

    /** @param array<string, mixed> $filters */
    private function perPage(array $filters): int
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return min(max($perPage, 5), 50);
    }
}
