<?php

namespace App\Modules\Telegram\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'telegram_user_id',
    'private_chat_id',
    'private_chat_started_at',
    'private_chat_available_at',
    'private_chat_unavailable_at',
    'last_delivery_error',
    'username',
    'first_name',
    'last_name',
    'language_code',
    'photo_url',
    'last_auth_at',
    'raw_data',
])]
final class TelegramAccount extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'private_chat_id' => 'integer',
            'private_chat_started_at' => 'datetime',
            'private_chat_available_at' => 'datetime',
            'private_chat_unavailable_at' => 'datetime',
            'last_auth_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
