<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Models\User;

final class CanonicalUserResolver
{
    public function resolve(User $user): User
    {
        return $user->canonical();
    }
}
