<?php

namespace App\Modules\Contract\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'contract_id',
    'permission',
])]
class ContractPermission extends Model
{
    use Auditable;

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
