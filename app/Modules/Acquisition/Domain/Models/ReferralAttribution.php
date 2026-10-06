<?php

namespace App\Modules\Acquisition\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'referrer_user_id',
    'referred_user_id',
    'source',
    'captured_at',
    'linked_at',
])]
final class ReferralAttribution extends Model
{
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    protected function casts(): array
    {
        return [
            'referrer_user_id' => 'integer',
            'referred_user_id' => 'integer',
            'captured_at' => 'immutable_datetime',
            'linked_at' => 'immutable_datetime',
        ];
    }
}
