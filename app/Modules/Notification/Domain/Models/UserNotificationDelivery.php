<?php

namespace App\Modules\Notification\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_notification_id',
    'channel',
    'status',
    'recipient',
    'external_message_id',
    'attempts',
    'last_error',
    'queued_at',
    'sent_at',
    'failed_at',
])]
final class UserNotificationDelivery extends Model
{
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(UserNotification::class, 'user_notification_id');
    }
}
