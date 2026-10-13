<?php

namespace App\Modules\Notification\Infrastructure\Broadcasting;

use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\ProfileRequestThreadAccess;
use App\Modules\Notification\Domain\Models\DirectConversation;
use App\Modules\Notification\Domain\Models\RequestThread;

final class ConversationChannel
{
    public function __construct(private readonly ProfileRequestThreadAccess $access) {}

    public function profile(User $user, string|int $id): bool
    {
        $thread = RequestThread::query()->find($id);
        if ($thread === null || $thread->type !== 'profile_change') {
            return false;
        }
        $request = ProfileChangeRequest::query()->find($thread->subject_id);

        return $request !== null && $this->access->canRead($user, $request, $thread);
    }

    public function direct(User $user, string|int $id): bool
    {
        $thread = DirectConversation::query()->find($id);
        if ($thread === null || $user->canonical()->isBlocked() || $user->canonical()->trashed()) {
            return false;
        }

        return in_array((int) $thread->user_low_id, $user->canonical()->identityIds(), true)
            || in_array((int) $thread->user_high_id, $user->canonical()->identityIds(), true);
    }
}
