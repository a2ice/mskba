<?php

namespace App\Modules\Shop\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ShopProductAttributeValue extends Model
{
    protected $fillable = ['product_id', 'attribute_id', 'value'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ShopProduct::class, 'product_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ShopAttribute::class, 'attribute_id');
    }
}
