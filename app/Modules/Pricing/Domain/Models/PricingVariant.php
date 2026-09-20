<?php

namespace App\Modules\Pricing\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'service_id',
    'code',
    'name',
    'description',
    'sort_order',
    'is_active',
])]
class PricingVariant extends Model
{
    use Auditable, SoftDeletes;

    public function service(): BelongsTo
    {
        return $this->belongsTo(PricingService::class, 'service_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PricingPrice::class, 'variant_id');
    }

    protected function casts(): array
    {
        return [
            'service_id' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
