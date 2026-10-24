<?php

namespace App\Modules\Favorites\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Favorite extends Model
{
    protected $fillable = [
        'user_id', 'guest_token_hash', 'entity_type', 'entity_id', 'trashed_at', 'notify_upcoming_events',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'entity_id' => 'integer',
            'trashed_at' => 'immutable_datetime',
            'notify_upcoming_events' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
