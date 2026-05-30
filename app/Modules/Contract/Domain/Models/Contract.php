<?php

namespace App\Modules\Contract\Domain\Models;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\VenueContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'number',
    'status',
    'starts_at',
    'expires_at',
])]
class Contract extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function venueContracts(): HasMany
    {
        return $this->hasMany(VenueContract::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContractStatusEnum::class,
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
