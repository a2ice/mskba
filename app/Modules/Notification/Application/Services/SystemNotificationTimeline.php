<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\UserNotification;

/** Read-only, private, cursor-paginated system notifications. */
final class SystemNotificationTimeline
{
    public function page(User $user, ?int $before = null): array
    {
        $query = UserNotification::query()->whereIn('user_id', $user->canonical()->identityIds())
            ->whereNull('payload->join_request_id')->whereNull('payload->direct_conversation_id')
            ->whereNull('payload->profile_change_request_id')->whereNull('payload->support_ticket_id')
            ->where(fn ($q) => $q->whereNull('payload->delivery_channels')
                ->orWhereJsonContains('payload->delivery_channels', 'in_app'))
            ->orderByDesc('id');
        if ($before !== null) {
            $query->where('id', '<', $before);
        }
        $found = $query->limit(11)->get();
        $page = $found->take(10);

        return [
            'entries' => $page->reverse()->values(),
            'next_before' => $found->count() > 10 ? (string) $page->last()->id : null,
        ];
    }
}
