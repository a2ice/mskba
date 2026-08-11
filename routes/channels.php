<?php

use App\Modules\Notification\Infrastructure\Broadcasting\UserNotificationChannel;
use Illuminate\Support\Facades\Broadcast;

// Reserved reusable private channel for notifications and account-scoped updates.
Broadcast::channel('users.{id}', UserNotificationChannel::class);
