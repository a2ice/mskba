<?php

namespace App\Modules\Notification\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'type',
    'status',
    'title',
    'body',
    'action_url',
    'payload',
    'read_at',
])]
class UserNotification extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markAsRead(): void
    {
        if ($this->status === UserNotificationStatusEnum::READ) {
            return;
        }

        $this->forceFill([
            'status' => UserNotificationStatusEnum::READ,
            'read_at' => now(),
        ])->save();
    }

    public function isNew(): bool
    {
        return $this->status === UserNotificationStatusEnum::NEW;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => UserNotificationTypeEnum::class,
            'status' => UserNotificationStatusEnum::class,
            'payload' => 'array',
            'read_at' => 'datetime',
        ];
    }
}
