<?php

namespace App\Modules\Shop\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shop\Application\Services\ShopCatalogService;
use App\Modules\Shop\Domain\Models\ShopCategory;
use App\Modules\Shop\Domain\Models\ShopProduct;
use App\Modules\Shop\Domain\Models\ShopTag;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ShopController extends Controller
{
    public function index(Request $request, ShopCatalogService $catalog): Response
    {
        return $this->catalog($request, $catalog);
    }

    public function category(Request $request, ShopCategory $category, ShopCatalogService $catalog): Response
    {
        return $this->catalog($request, $catalog, $category);
    }

    private function catalog(Request $request, ShopCatalogService $catalog, ?ShopCategory $category = null): Response
    {
        $visibleIds = $catalog->visibleCategoryIds();
        abort_if($category && ! in_array($category->id, $visibleIds, true), 404);

        $search = trim((string) $request->query('q', ''));
        $search = mb_substr($search, 0, 100);
        $tag = mb_substr(trim((string) $request->query('tag', '')), 0, 110);
        $activeCategoryIds = $category
            ? $catalog->descendantIds($category->id, $visibleIds)
            : $visibleIds;

        $products = ShopProduct::query()
            ->where('status', 'published')
            ->whereHas('categories', fn ($query) => $query->whereIn('shop_categories.id', $activeCategoryIds))
            ->with(['categories', 'tags'])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->whereRaw('LOWER(shop_products.name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(shop_products.sku) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(shop_products.description) LIKE ?', ['%'.mb_strtolower($search).'%'])))
            ->when($tag !== '', fn ($query) => $query->whereHas('tags',
                fn ($q) => $q->where('shop_tags.slug', $tag)))
            ->orderBy('sort_order')->orderByDesc('id')->paginate(12)->withQueryString();

        $categories = ShopCategory::query()->whereIn('id', $visibleIds)
            ->where('parent_id', $category?->id)->orderBy('sort_order')->orderBy('name')->get();

        $breadcrumbs = [];
        $ancestor = $category;
        while ($ancestor !== null) {
            array_unshift($breadcrumbs, $ancestor);
            $ancestor = $ancestor->parent;
        }

        $tags = ShopTag::query()->whereHas('products', fn ($query) => $query
            ->where('status', 'published')->whereHas('categories', fn ($q) => $q
            ->whereIn('shop_categories.id', $activeCategoryIds)))->orderBy('name')->get();

        return ThemeResolver::page('shop.index', compact(
            'category', 'categories', 'products', 'breadcrumbs', 'search', 'tag', 'tags',
        ));
    }

    public function product(ShopProduct $product, ShopCatalogService $catalog): Response
    {
        $visibleIds = $catalog->visibleCategoryIds();
        abort_unless($product->status === 'published'
            && $product->categories()->whereIn('shop_categories.id', $visibleIds)->exists(), 404);

        $product->load(['categories', 'tags', 'attributeValues.attribute']);
        $visibleCategories = $product->categories->whereIn('id', $visibleIds);
        $breadcrumbs = $visibleCategories->first();

        $allowedAttributes = $catalog->attributesForCategoryIds($visibleCategories->pluck('id')->all())
            ->pluck('id')->all();
        $characteristics = $product->attributeValues
            ->filter(fn ($value) => in_array($value->attribute_id, $allowedAttributes, true))
            ->sortBy(fn ($value) => $value->attribute?->sort_order ?? 100)
            ->values();

        return ThemeResolver::page('shop.product', compact(
            'product', 'breadcrumbs', 'characteristics', 'visibleCategories',
        ));
    }
}
