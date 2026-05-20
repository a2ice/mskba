<?php

namespace App\Modules\Identity\Application\Contracts;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Application\DTO\UserQueryFiltersDTO;

interface UserReadRepositoryContract
{
    public function findByResolvedLogin(string $normalizedLogin, bool $isContact): ?User;

    public function findByLoginOrContact(string $normalizedLogin, bool $isContact): ?User;

    public function findById(int $userId): ?User;

    public function getAllUsers(?UserQueryFiltersDTO $filters): array;
}
