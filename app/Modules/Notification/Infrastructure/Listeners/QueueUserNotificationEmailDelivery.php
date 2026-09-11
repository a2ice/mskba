<?php

namespace App\Modules\Notification\Infrastructure\Listeners;

use App\Modules\Notification\Domain\Events\UserNotificationCreated;
use App\Modules\Notification\Infrastructure\Jobs\SendUserNotificationEmailJob;

final class QueueUserNotificationEmailDelivery
{
    public function handle(UserNotificationCreated $event): void
    {
        SendUserNotificationEmailJob::dispatch($event->notificationId)->afterCommit();
    }
}
