<?php

namespace App\Modules\Shop\Application\Services;

use App\Modules\Shop\Domain\Models\ShopAttribute;
use App\Modules\Shop\Domain\Models\ShopCategory;
use App\Modules\Shop\Domain\Models\ShopProduct;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

final class ShopCatalogService
{
    /** A category is public only when it and every ancestor are published. */
    public function visibleCategoryIds(): array
    {
        $categories = ShopCategory::query()->get(['id', 'parent_id', 'status'])->keyBy('id');
        $visible = [];
        $resolving = [];

        $isVisible = function (int $id) use (&$isVisible, &$visible, &$resolving, $categories): bool {
            if (array_key_exists($id, $visible)) {
                return $visible[$id];
            }
            if (isset($resolving[$id])) {
                return false;
            }
            $category = $categories->get($id);
            if ($category === null || $category->status !== 'published') {
                return $visible[$id] = false;
            }
            $resolving[$id] = true;
            $result = $category->parent_id === null || $isVisible((int) $category->parent_id);
            unset($resolving[$id]);

            return $visible[$id] = $result;
        };

        foreach ($categories->keys() as $id) {
            $isVisible((int) $id);
        }

        return array_map('intval', array_keys(array_filter($visible)));
    }

    /** All public descendants including the requested category. */
    public function descendantIds(int $categoryId, array $visibleIds): array
    {
        $children = ShopCategory::query()->whereIn('id', $visibleIds)
            ->get(['id', 'parent_id'])->groupBy('parent_id');
        $ids = [];
        $walk = function (int $id) use (&$walk, &$ids, $children): void {
            if (isset($ids[$id])) {
                return;
            }
            $ids[$id] = true;
            foreach ($children->get($id, collect()) as $child) {
                $walk((int) $child->id);
            }
        };
        if (in_array($categoryId, $visibleIds, true)) {
            $walk($categoryId);
        }

        return array_keys($ids);
    }

    /** Legacy fallback: only attributes attached DIRECTLY to these categories. No parent inheritance. */
    public function attributesForCategoryIds(array $categoryIds): EloquentCollection
    {
        if ($categoryIds === []) {
            return new EloquentCollection;
        }

        return ShopAttribute::query()->whereHas('categories', fn ($query) => $query
            ->whereIn('shop_categories.id', array_map('intval', $categoryIds)))
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    public function attributesForProductType(?int $typeId): EloquentCollection
    {
        if (! $typeId) {
            return new EloquentCollection;
        }

        return ShopAttribute::query()->whereHas('productTypes', fn ($query) => $query
            ->where('shop_product_types.id', $typeId))
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    public function attributesForProduct(ShopProduct $product): EloquentCollection
    {
        if ($product->product_type_id !== null) {
            return $this->attributesForProductType((int) $product->product_type_id);
        }

        // Compatibility for catalog items made before product types were introduced.
        return $this->attributesForCategoryIds($product->categories()->pluck('shop_categories.id')->all());
    }

    public function characteristicRows(array $categoryIds, array $currentRows = [], ?int $typeId = null): array
    {
        $values = collect($currentRows)->filter(fn ($row) => is_array($row) && isset($row['attribute_id']))
            ->mapWithKeys(fn ($row): array => [(int) $row['attribute_id'] => (string) ($row['value'] ?? '')]);

        $attributes = $typeId !== null
            ? $this->attributesForProductType($typeId)
            : $this->attributesForCategoryIds($categoryIds);

        return $attributes->map(
            fn (ShopAttribute $attribute): array => [
                'attribute_id' => $attribute->id,
                'value' => $values->get($attribute->id, ''),
            ],
        )->all();
    }

    /** Save values only for the selected type (or directly assigned legacy category fields). */
    public function syncAttributes(ShopProduct $product, array $rows): void
    {
        $allowed = $this->attributesForProduct($product)->keyBy('id');
        $submitted = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['attribute_id'])) {
                continue;
            }

            $id = (int) $row['attribute_id'];
            $attribute = $allowed->get($id);
            if (! $attribute || isset($submitted[$id])) {
                throw ValidationException::withMessages(['data.characteristics' => 'Недопустимая или повторяющаяся характеристика.']);
            }
            $value = trim((string) ($row['value'] ?? ''));
            if (mb_strlen($value) > 5000 || (
                $value !== '' && (
                    ($attribute->type === 'number' && ! is_numeric($value))
                    || ($attribute->type === 'boolean' && ! in_array($value, ['0', '1'], true))
                    || ($attribute->type === 'select' && ! in_array($value, (array) $attribute->options, true))
                )
            )) {
                throw ValidationException::withMessages([
                    'data.characteristics' => 'Некорректное значение характеристики «'.$attribute->name.'».',
                ]);
            }
            $submitted[$id] = $value;
        }

        $product->attributeValues()->whereNotIn('attribute_id', array_keys($submitted))->delete();
        foreach ($submitted as $attributeId => $value) {
            if ($value === '') {
                $product->attributeValues()->where('attribute_id', $attributeId)->delete();

                continue;
            }
            $product->attributeValues()->updateOrCreate(
                ['attribute_id' => $attributeId],
                ['value' => $value],
            );
        }
    }
}
