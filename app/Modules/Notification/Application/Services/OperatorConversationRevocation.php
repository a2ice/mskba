<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\UserNotificationCounterStore;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Infrastructure\Broadcasting\UserNotificationCountUpdatedBroadcast;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Prevent old toast previews from disclosing a revoked operator's history. */
final class OperatorConversationRevocation
{
    public function revokeForUser(User $operator): void
    {
        $userId = (int) $operator->canonical()->id;
        $redacted = UserNotification::query()
            ->where('user_id', $userId)
            ->where('payload->source', 'operator.message')
            ->where('payload->operator_user_id', $userId)
            ->update([
                'title' => 'Операторские диалоги MSKBA',
                'body' => 'Доступ к переписке прекращён.',
                'action_url' => route('account.notifications'),
                'action_text' => null,
                'status' => UserNotificationStatusEnum::READ,
                'read_at' => now(),
            ]);

        if ($redacted === 0) {
            return;
        }

        app(UserNotificationCounterStore::class)->forget($userId);
        DB::afterCommit(function () use ($userId): void {
            try {
                UserNotificationCountUpdatedBroadcast::dispatch(
                    $userId,
                    app(UserNotificationCounterStore::class)->get($userId),
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
