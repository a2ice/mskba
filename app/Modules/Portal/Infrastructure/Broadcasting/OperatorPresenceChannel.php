<?php

namespace App\Modules\Portal\Infrastructure\Broadcasting;

use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\OperatorConversation;

final class OperatorPresenceChannel
{
    public function join(User $viewer, int|string $recipientId): bool
    {
        $viewer = $viewer->canonical();
        if ($viewer->isBlocked() || $viewer->trashed()
            || ! ctype_digit((string) $recipientId) || (int) $recipientId < 1) {
            return false;
        }

        $permissions = app(UserOperationalPermissionChecker::class);
        if (! $permissions->allows($viewer, Permission::BACKOFFICE_ACCESS)
            || ! $permissions->allows($viewer, Permission::OPERATOR_CONVERSATION_HANDLE)
            || ! $permissions->allows($viewer, Permission::OPERATOR_PRESENCE_VIEW)) {
            return false;
        }

        // No operator may monitor a user merely by guessing a channel ID.
        return OperatorConversation::query()
            ->where('operator_user_id', $viewer->id)
            ->where('recipient_user_id', (int) $recipientId)
            ->exists();
    }
}
