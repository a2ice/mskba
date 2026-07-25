<?php

namespace App\Modules\Telegram\Domain\Models;

use App\Modules\Coordination\Domain\Models\Poll;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'poll_id',
    'chat_id',
    'message_id',
    'telegram_poll_id',
    'status',
    'last_error',
    'published_at',
    'synced_at',
])]
class TelegramCoordinationPublication extends Model
{
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'chat_id');
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
