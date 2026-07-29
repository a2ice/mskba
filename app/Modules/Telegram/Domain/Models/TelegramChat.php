<?php

namespace App\Modules\Telegram\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'telegram_chat_id',
    'title',
    'type',
    'is_active',
    'publishes_coordination',
])]
class TelegramChat extends Model
{
    public function coordinationPublications(): HasMany
    {
        return $this->hasMany(TelegramCoordinationPublication::class, 'chat_id');
    }

    public function contentPublications(): HasMany
    {
        return $this->hasMany(TelegramContentPublication::class, 'chat_id');
    }

    protected function casts(): array
    {
        return [
            'telegram_chat_id' => 'integer',
            'is_active' => 'boolean',
            'publishes_coordination' => 'boolean',
        ];
    }
}
