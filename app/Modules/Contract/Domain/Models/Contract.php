<?php

namespace App\Modules\Contract\Domain\Models;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Venue\Domain\Models\VenueContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'number',
    'name',
    'status',
    'starts_at',
    'expires_at',
    'assigned_by',
    'assigned_at',
    'assigner',
    'comment',
])]
class Contract extends Model
{
    public function parties(): HasMany
    {
        return $this->hasMany(ContractParty::class);
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
            'assigned_at' => 'datetime',
        ];
    }
}
