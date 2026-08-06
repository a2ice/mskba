<?php

namespace App\Modules\Notification\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class UserNotificationCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $notificationId) {}
}
