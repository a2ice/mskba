<?php

namespace App\Modules\Contract\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'contract_id',
    'relation_type',
    'left_type',
    'left_id',
    'left_role',
    'right_type',
    'right_id',
    'right_role',
])]
class ContractRelation extends Model
{
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
