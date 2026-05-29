<?php

namespace App\Modules\Contract\Application\Services;

use App\Modules\Contract\Application\Contracts\ContractAccessInterface;
use App\Modules\Identity\Domain\Models\User;

final class NullContractAccessService implements ContractAccessInterface
{
    public function allows(User $user, string $subjectType, int $subjectId, string $permission): bool
    {
        return false;
    }

    /**
     * @return array<int>
     */
    public function allowedSubjectIds(User $user, string $subjectType, string $permission): array
    {
        return [];
    }
}
