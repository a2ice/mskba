<?php

namespace App\Modules\Contract\Domain\Models;

use App\Modules\Contract\Domain\Enums\ContractPartyRoleEnum;
use App\Modules\Contract\Domain\Enums\ContractPartyTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'contract_id',
    'party_type',
    'party_id',
    'role',
])]
class ContractParty extends Model
{
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'party_type' => ContractPartyTypeEnum::class,
            'role' => ContractPartyRoleEnum::class,
        ];
    }
}
