<?php

namespace App\Modules\Notification\Infrastructure\Listeners;

use App\Modules\Notification\Application\Services\UserNotificationCounterStore;
use App\Modules\Notification\Domain\Events\UserNotificationCreated;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Infrastructure\Broadcasting\UserNotificationCreatedBroadcast;
use App\Modules\Notification\Presentation\Presenters\UserNotificationPresenter;

final class BroadcastUserNotificationCreated
{
    public function __construct(
        private readonly UserNotificationPresenter $presenter,
        private readonly UserNotificationCounterStore $counterStore,
    ) {}

    public function handle(UserNotificationCreated $event): void
    {
        $notification = UserNotification::query()->find($event->notificationId);
        if ($notification === null) {
            return;
        }

        try {
            UserNotificationCreatedBroadcast::dispatch(
                (int) $notification->user_id,
                $this->presenter->present($notification),
                $this->counterStore->get((int) $notification->user_id),
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
