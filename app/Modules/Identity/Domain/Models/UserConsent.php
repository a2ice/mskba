<?php

namespace App\Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'type',
    'document_version',
    'accepted_at',
    'source',
    'ip_address',
    'user_agent',
    'revoked_at',
])]
class UserConsent extends Model
{
    public const TYPE_PRIVACY_POLICY = 'privacy_policy';

    protected function casts(): array
    {
        return [
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
