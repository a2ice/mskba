<?php

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Contracts\UserReadRepositoryContract;
use App\Modules\Identity\Domain\Models\User;

class EloquentUserReadRepository implements UserReadRepositoryContract
{
    public function findByResolvedLogin(string $normalizedLogin, bool $isContact): ?User
    {
        $column = $isContact ? 'email' : 'login';

        return User::query()
            ->whereRaw('LOWER(' . $column . ') = ?', [$normalizedLogin])
            ->first();
    }
}
