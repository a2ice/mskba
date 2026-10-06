<?php

namespace App\Modules\Rewards\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reward_id',
    'version_number',
    'amount_minor',
    'currency',
    'mechanism_code',
    'conditions',
    'recipient_description',
    'trigger_description',
    'mechanism_parameters',
    'valid_from',
    'valid_until',
])]
class RewardVersion extends Model
{
    use Auditable;

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class)->withTrashed();
    }

    public function formattedAmount(): string
    {
        return number_format($this->amount_minor / 100, 2, ',', ' ').' '.$this->currency;
    }

    protected function casts(): array
    {
        return [
            'reward_id' => 'integer',
            'version_number' => 'integer',
            'amount_minor' => 'integer',
            'mechanism_parameters' => 'array',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }
}
