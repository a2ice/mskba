<?php

namespace App\Modules\Contract\Application\Contracts;

use App\Modules\Identity\Domain\Models\User;

interface ContractAccessInterface
{
    public function allows(User $user, string $subjectType, int $subjectId, string $permission): bool;

    /**
     * @return array<int>
     */
    public function allowedSubjectIds(User $user, string $subjectType, string $permission): array;
}
