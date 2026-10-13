<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;

final class GetSystemConversationPreviewHandler
{
    /**
     * All existing user_notifications have MSKBA as their sender.
     * Future person-to-person messages will have their own sender identity,
     * not an invented sender inferred from a notification title/type.
     *
     * @return array{last: UserNotification, preview: UserNotification, unread_count: int}|null
     */
    public function handle(User $user): ?array
    {
        $identityIds = $user->canonical()->identityIds();
        $base = UserNotification::query()->whereIn('user_id', $identityIds)
            ->whereNull('payload->join_request_id')
            ->whereNull('payload->direct_conversation_id')
            ->whereNull('payload->profile_change_request_id')->whereNull('payload->support_ticket_id');

        $last = (clone $base)->orderByDesc('created_at')->orderByDesc('id')->first();
        if ($last === null) {
            return null;
        }

        $unread = (clone $base)->where('status', UserNotificationStatusEnum::NEW);

        return [
            'last' => $last,
            'preview' => (clone $unread)->orderByDesc('created_at')->orderByDesc('id')->first() ?? $last,
            'unread_count' => $unread->count(),
        ];
    }
}
