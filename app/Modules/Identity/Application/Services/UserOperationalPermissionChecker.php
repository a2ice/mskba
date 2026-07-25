<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Models\User;

final class UserOperationalPermissionChecker
{
    public function allows(User $user, UserOperationalPermissionEnum $permission): bool
    {
        // Первая версия намеренно разрешает все известные операционные права.
        // Эта точка будет читать персональный snapshot permissions, когда появится
        // административное управление ими.
        return ! $user->isBlocked() && ! $user->trashed();
    }
}
