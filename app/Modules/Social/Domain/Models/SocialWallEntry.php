<?php

namespace App\Modules\Social\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SocialWallEntry extends Model
{
    protected $table = 'social_wall_entries';

    protected $fillable = [
        'scope_type', 'scope_id', 'actor_user_id', 'target_user_id',
        'kind', 'body', 'metadata', 'client_request_id', 'deleted_at',
        'deleted_by_user_id', 'hidden_at',
    ];

    protected function casts(): array
    {
        return [
            'scope_id' => 'integer',
            'actor_user_id' => 'integer',
            'target_user_id' => 'integer',
            'metadata' => 'array',
            'deleted_at' => 'immutable_datetime',
            'hidden_at' => 'immutable_datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
