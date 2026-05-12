<?php

namespace App\Modules\Identity\Application\Contracts;

use App\Modules\Identity\Domain\Models\User;

interface UserReadRepositoryContract
{
    public function findByResolvedLogin(string $normalizedLogin, bool $isContact): ?User;

    public function findById(int $userId): ?User;
}
