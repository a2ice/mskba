<?php

namespace App\Modules\Pricing\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'category_id',
    'code',
    'name',
    'description',
    'sort_order',
    'is_active',
])]
class PricingService extends Model
{
    use Auditable, SoftDeletes;

    public function category(): BelongsTo
    {
        return $this->belongsTo(PricingCategory::class, 'category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(PricingVariant::class, 'service_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PricingPrice::class, 'service_id');
    }

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
