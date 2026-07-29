<?php

namespace App\Modules\Telegram\Domain\Models;

use App\Modules\Content\Domain\Models\ContentItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'content_item_id',
    'chat_id',
    'message_id',
    'message_type',
    'is_enabled',
    'status',
    'last_error',
    'published_at',
    'synced_at',
])]
final class TelegramContentPublication extends Model
{
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'chat_id');
    }

    protected function casts(): array
    {
        return [
            'message_id' => 'integer',
            'is_enabled' => 'boolean',
            'published_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
