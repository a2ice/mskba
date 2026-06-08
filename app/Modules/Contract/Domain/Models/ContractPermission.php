<?php

namespace App\Modules\Contract\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'contract_id',
    'permission',
])]
class ContractPermission extends Model
{
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
