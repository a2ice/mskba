<?php

namespace App\Modules\Telegram\Domain\Models;

use App\Modules\Event\Domain\Models\Event;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id',
    'chat_id',
    'message_id',
    'status',
    'last_error',
    'published_at',
    'synced_at',
])]
final class TelegramEventPublication extends Model
{
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    protected function casts(): array
    {
        return [
            'message_id' => 'integer',
            'published_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
