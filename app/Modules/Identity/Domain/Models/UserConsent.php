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
    'payload',
    'revoked_at',
])]
class UserConsent extends Model
{
    public const TYPE_PERSONAL_DATA_PROCESSING = 'personal_data_processing';

    public const TYPE_PERSONAL_DATA_DISTRIBUTION = 'personal_data_distribution';

    /** Historical records created before the standalone consent document. */
    public const TYPE_PRIVACY_POLICY_LEGACY = 'privacy_policy';

    protected function casts(): array
    {
        return [
            'accepted_at' => 'immutable_datetime',
            'payload' => 'array',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
