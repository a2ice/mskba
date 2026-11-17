<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\OperatorConversation;
use Illuminate\Database\Eloquent\Builder;

/** Access is independent of the privacy settings governing personal DMs. */
final class OperatorConversationAccess
{
    public function __construct(private readonly UserOperationalPermissionChecker $permissions) {}

    public function isOperator(User $actor): bool
    {
        return $this->permissions->allows($actor, Permission::BACKOFFICE_ACCESS)
            && $this->permissions->allows($actor, Permission::OPERATOR_CONVERSATION_HANDLE);
    }

    public function mayContact(User $operator, User $recipient): bool
    {
        $operator = $operator->canonical();
        $recipient = $recipient->canonical();

        return $this->isOperator($operator)
            && $operator->id !== $recipient->id
            && ! $recipient->isBlocked() && ! $recipient->trashed()
            && $recipient->system_role->value !== 'system';
    }

    public function canRead(User $actor, OperatorConversation $conversation): bool
    {
        $actor = $actor->canonical();
        if ($actor->isBlocked() || $actor->trashed()) {
            return false;
        }

        if ((int) $conversation->recipient_user_id === $actor->id) {
            return true;
        }

        return (int) $conversation->operator_user_id === $actor->id
            && $this->isOperator($actor);
    }

    public function canWrite(User $actor, OperatorConversation $conversation): bool
    {
        if (! $this->canRead($actor, $conversation)) {
            return false;
        }
        $operator = $conversation->operator;
        $recipient = $conversation->recipient;

        return $operator !== null && $recipient !== null
            && $this->mayContact($operator, $recipient);
    }

    public function visibleForOperator(User $operator): Builder
    {
        return OperatorConversation::query()->where(
            'operator_user_id', $operator->canonical()->id,
        );
    }
}
