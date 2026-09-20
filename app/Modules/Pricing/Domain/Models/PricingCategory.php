<?php

namespace App\Modules\Pricing\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code',
    'name',
    'description',
    'sort_order',
    'is_active',
])]
class PricingCategory extends Model
{
    use Auditable, SoftDeletes;

    public function services(): HasMany
    {
        return $this->hasMany(PricingService::class, 'category_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
