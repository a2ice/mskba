<?php

namespace App\Modules\Identity\Application\Queries;

use App\Modules\Identity\Application\Contracts\UserReadRepositoryContract;
use App\Modules\Identity\Application\DTO\UserQueryFiltersDTO;
use App\Modules\Identity\Domain\Models\User;

class GetAdminUserQuery
{
    public function __construct(private UserReadRepositoryContract $userRepository) {}

    public function execute(int $userId): ?User
    {
        return $this->userRepository->findById($userId);
    }
}