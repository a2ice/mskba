<?php

namespace App\Modules\Shop\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ShopProduct extends Model
{
    protected $fillable = [
        'name', 'slug', 'sku', 'description', 'status', 'stock_status',
        'stock_quantity', 'price_kopecks', 'old_price_kopecks', 'images', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'stock_quantity' => 'integer',
            'sort_order' => 'integer',
            'price_kopecks' => 'integer',
            'old_price_kopecks' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $product): void {
            if ($product->price_kopecks !== null && $product->old_price_kopecks !== null
                && $product->old_price_kopecks < $product->price_kopecks) {
                throw ValidationException::withMessages([
                    'data.old_price_rub' => 'Старая цена не может быть меньше актуальной.',
                ]);
            }
        });
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ShopCategory::class, 'shop_category_product', 'product_id', 'category_id');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(ShopTag::class, 'taggable', 'shop_taggables', 'taggable_id', 'shop_tag_id');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ShopProductAttributeValue::class, 'product_id');
    }

    public function imageUrls(): array
    {
        return array_map(
            static fn (string $path): string => Storage::disk('public')->url($path),
            array_values(array_filter((array) $this->images, 'is_string')),
        );
    }

    public static function rubles(?int $kopecks): ?string
    {
        return $kopecks === null ? null : number_format($kopecks / 100, 2, ',', ' ');
    }
}
