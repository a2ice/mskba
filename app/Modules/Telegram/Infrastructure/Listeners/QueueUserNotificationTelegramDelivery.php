<?php

namespace App\Modules\Telegram\Infrastructure\Listeners;

use App\Modules\Notification\Domain\Events\UserNotificationCreated;
use App\Modules\Telegram\Infrastructure\Jobs\SendUserNotificationToTelegramJob;

final class QueueUserNotificationTelegramDelivery
{
    public function handle(UserNotificationCreated $event): void
    {
        SendUserNotificationToTelegramJob::dispatch($event->notificationId)->afterCommit();
    }
}
