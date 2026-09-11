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
    public const TYPE_PERSONAL_DATA_PROCESSING = 'personal_data_processing';

    /**
     * @deprecated Historical constant name kept so existing registration callers
     * write the standalone personal-data consent type without a breaking refactor.
     */
    public const TYPE_PRIVACY_POLICY = self::TYPE_PERSONAL_DATA_PROCESSING;

    /** Value used by consent records created before the standalone consent document. */
    public const TYPE_PRIVACY_POLICY_LEGACY = 'privacy_policy';

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
