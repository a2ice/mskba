<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;

/**
 * Editing one's own profile/participation roles is distinct from delegating
 * permissions. No self-edit privilege may authorize a change to system access.
 */
final class SuperadminSelfEditAccess
{
    public static function allows(User $actor, User $target): bool
    {
        $actor = $actor->canonical();
        $target = $target->canonical();

        return $actor->id === $target->id
            && $actor->system_role === UserSystemRoleEnum::SUPERADMIN
            && $actor->isConfirmed()
            && ! $actor->isBlocked()
            && ! $actor->trashed();
    }
}
