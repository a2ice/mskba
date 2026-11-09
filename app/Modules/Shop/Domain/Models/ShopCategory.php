<?php

namespace App\Modules\Shop\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ShopCategory extends Model
{
    protected $fillable = ['parent_id', 'default_product_type_id', 'name', 'slug', 'description', 'status', 'sort_order', 'images'];

    protected function casts(): array
    {
        return ['images' => 'array', 'sort_order' => 'integer', 'parent_id' => 'integer'];
    }

    protected static function booted(): void
    {
        self::saving(function (self $category): void {
            $parentId = $category->parent_id;
            $seen = [];

            while ($parentId !== null) {
                if ($parentId === $category->id || isset($seen[$parentId])) {
                    throw ValidationException::withMessages([
                        'data.parent_id' => 'Категория не может быть родителем самой себе или своего предка.',
                    ]);
                }

                $seen[$parentId] = true;
                $parentId = self::query()->whereKey($parentId)->value('parent_id');
            }
        });
    }

    public function defaultProductType(): BelongsTo
    {
        return $this->belongsTo(ShopProductType::class, 'default_product_type_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(ShopProduct::class, 'shop_category_product', 'category_id', 'product_id');
    }

    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(ShopAttribute::class, 'shop_attribute_category', 'category_id', 'attribute_id');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(ShopTag::class, 'taggable', 'shop_taggables', 'taggable_id', 'shop_tag_id');
    }

    public function imageUrls(): array
    {
        return array_map(
            static fn (string $path): string => Storage::disk('public')->url($path),
            array_values(array_filter((array) $this->images, 'is_string')),
        );
    }
}
