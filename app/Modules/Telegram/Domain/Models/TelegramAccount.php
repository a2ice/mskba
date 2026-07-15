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
            'last_auth_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
