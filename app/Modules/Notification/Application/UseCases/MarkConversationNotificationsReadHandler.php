<?php

namespace App\Modules\Notification\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\UserNotificationCounterStore;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Infrastructure\Broadcasting\UserNotificationCountUpdatedBroadcast;

final class MarkConversationNotificationsReadHandler
{
    public function __construct(private readonly UserNotificationCounterStore $counts) {}

    public function handle(User $user, ?int $joinRequestId = null, ?int $directConversationId = null): int
    {
        $canonical = $user->canonical();
        $query = UserNotification::query()
            ->whereIn('user_id', $canonical->identityIds())
            ->where('status', UserNotificationStatusEnum::NEW);

        if ($joinRequestId !== null) {
            $query->where('payload->join_request_id', $joinRequestId);
        } elseif ($directConversationId !== null) {
            $query->where('payload->direct_conversation_id', $directConversationId);
        } else {
            $query->whereNull('payload->join_request_id')->whereNull('payload->direct_conversation_id');
        }

        $updated = $query->update([
            'status' => UserNotificationStatusEnum::READ,
            'read_at' => now(),
        ]);

        if ($updated > 0) {
            $this->counts->forget((int) $canonical->id);
            try {
                UserNotificationCountUpdatedBroadcast::dispatch(
                    (int) $canonical->id,
                    $this->counts->get((int) $canonical->id),
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $this->counts->get((int) $canonical->id);
    }
}
