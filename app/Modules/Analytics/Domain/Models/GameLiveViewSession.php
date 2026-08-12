<?php

namespace App\Modules\Analytics\Domain\Models;

use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserFingerprint;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'game_id',
    'user_id',
    'user_fingerprint_id',
    'viewer_key_hash',
    'started_at',
    'last_seen_at',
    'watched_seconds',
])]
final class GameLiveViewSession extends Model
{
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fingerprint(): BelongsTo
    {
        return $this->belongsTo(UserFingerprint::class, 'user_fingerprint_id');
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'watched_seconds' => 'integer',
        ];
    }
}
