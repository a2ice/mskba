<?php

namespace App\Modules\Shop\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

final class ShopTag extends Model
{
    protected $fillable = ['name', 'slug'];

    public function categories(): MorphToMany
    {
        return $this->morphedByMany(ShopCategory::class, 'taggable', 'shop_taggables', 'shop_tag_id', 'taggable_id');
    }

    public function products(): MorphToMany
    {
        return $this->morphedByMany(ShopProduct::class, 'taggable', 'shop_taggables', 'shop_tag_id', 'taggable_id');
    }
}
