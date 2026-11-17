<?php

namespace App\Modules\Shop\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ShopAttribute extends Model
{
    protected $fillable = ['name', 'code', 'type', 'unit', 'options', 'sort_order'];

    protected function casts(): array
    {
        return ['options' => 'array', 'sort_order' => 'integer'];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ShopCategory::class, 'shop_attribute_category', 'attribute_id', 'category_id');
    }

    public function productTypes(): BelongsToMany
    {
        return $this->belongsToMany(ShopProductType::class, 'shop_attribute_product_type', 'attribute_id', 'product_type_id');
    }

    public function productValues(): HasMany
    {
        return $this->hasMany(ShopProductAttributeValue::class, 'attribute_id');
    }
}
