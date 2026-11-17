<?php

namespace App\Modules\Shop\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ShopProductType extends Model
{
    protected $fillable = ['name', 'code', 'description', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(ShopAttribute::class, 'shop_attribute_product_type',
            'product_type_id', 'attribute_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(ShopProduct::class, 'product_type_id');
    }

    public function defaultCategories(): HasMany
    {
        return $this->hasMany(ShopCategory::class, 'default_product_type_id');
    }
}
