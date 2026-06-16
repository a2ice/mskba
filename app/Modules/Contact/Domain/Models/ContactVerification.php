<?php

namespace App\Modules\Contact\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Contact\Domain\Enums\ContactVerificationChannelEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'contact_id',
    'channel',
    'status',
    'code_hash',
    'sent_to',
    'attempts_count',
    'max_attempts',
    'expires_at',
    'verified_at',
    'failed_at',
    'meta',
])]
class ContactVerification extends Model
{
    use Auditable;

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isConfirmed(): bool
    {
        return $this->status === ContactVerificationStatusEnum::CONFIRMED;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ContactVerificationChannelEnum::class,
            'status' => ContactVerificationStatusEnum::class,
            'attempts_count' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'failed_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
