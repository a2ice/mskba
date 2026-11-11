<?php

namespace App\Modules\Shop\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Favorites\Application\Services\FavoritesManager;
use App\Modules\Shop\Application\Services\ShopCatalogService;
use App\Modules\Shop\Domain\Models\ShopCategory;
use App\Modules\Shop\Domain\Models\ShopProduct;
use App\Modules\Shop\Domain\Models\ShopProductAttributeValue;
use App\Modules\Shop\Domain\Models\ShopProductType;
use App\Modules\Shop\Domain\Models\ShopTag;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Database\Eloquent\Collection;
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

    public function create(): Response
    {
        return ThemeResolver::page('shop.create');
    }

    private function catalog(Request $request, ShopCatalogService $catalog, ?ShopCategory $category = null): Response
    {
        $visibleIds = $catalog->visibleCategoryIds();
        abort_if($category && ! in_array($category->id, $visibleIds, true), 404);

        $activeIds = $category
            ? $catalog->descendantIds($category->id, $visibleIds)
            : $visibleIds;

        $filters = [
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
            'tag' => mb_substr(trim((string) $request->query('tag', '')), 0, 110),
            'product_type' => (int) $request->query('product_type', 0),
            'stock' => in_array($request->query('stock'), ['in_stock', 'out_of_stock', 'preorder', 'on_request'], true)
                ? $request->query('stock') : '',
            'color' => mb_substr(trim((string) $request->query('color', '')), 0, 100),
            'size' => mb_substr(trim((string) $request->query('size', '')), 0, 100),
            'sort' => in_array($request->query('sort'), ['price_asc', 'price_desc', 'new'], true)
                ? $request->query('sort') : '',
            'view' => $request->query('view') === 'list' ? 'list' : 'cards',
            'min_price' => $this->priceFilter($request->query('min_price')),
            'max_price' => $this->priceFilter($request->query('max_price')),
        ];
        $baseProducts = ShopProduct::query()
            ->where('status', 'published')
            ->whereHas('categories', fn ($query) => $query->whereIn('shop_categories.id', $activeIds));

        $products = (clone $baseProducts)->with(['categories', 'tags', 'productType'])
            ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($q) => $q
                ->whereRaw('LOWER(shop_products.name) LIKE ?', ['%'.mb_strtolower($filters['q']).'%'])
                ->orWhereRaw('LOWER(shop_products.sku) LIKE ?', ['%'.mb_strtolower($filters['q']).'%'])
                ->orWhereRaw('LOWER(shop_products.description) LIKE ?', ['%'.mb_strtolower($filters['q']).'%'])))
            ->when($filters['tag'] !== '', fn ($query) => $query->whereHas('tags',
                fn ($q) => $q->where('shop_tags.slug', $filters['tag'])))
            ->when($filters['product_type'] > 0, fn ($query) => $query
                ->where('product_type_id', $filters['product_type']))
            ->when($filters['stock'] !== '', fn ($query) => $query
                ->where('stock_status', $filters['stock']))
            ->when($filters['min_price'] !== null, fn ($query) => $query
                ->where('price_kopecks', '>=', $filters['min_price'] * 100))
            ->when($filters['max_price'] !== null, fn ($query) => $query
                ->where('price_kopecks', '<=', $filters['max_price'] * 100));

        foreach (['color', 'size'] as $code) {
            if ($filters[$code] !== '') {
                $products->whereHas('attributeValues', fn ($query) => $query
                    ->where('value', $filters[$code])->whereHas('attribute',
                        fn ($q) => $q->where('code', $code)));
            }
        }

        match ($filters['sort']) {
            'price_asc' => $products->orderByRaw('CASE WHEN price_kopecks IS NULL THEN 1 ELSE 0 END')
                ->orderBy('price_kopecks'),
            'price_desc' => $products->orderByDesc('price_kopecks'),
            'new' => $products->orderByDesc('created_at'),
            default => $products->orderBy('sort_order'),
        };
        $products = $products->orderByDesc('id')->paginate(10)->withQueryString();

        $allCategories = ShopCategory::query()->whereIn('id', $visibleIds)
            ->orderBy('sort_order')->orderBy('name')->get();
        $categoryNav = $this->categoryNavigation($allCategories);
        $categoryTree = $this->categoryTree($allCategories);
        $expandedCategoryIds = [];
        $cursor = $category;
        while ($cursor && ! in_array($cursor->id, $expandedCategoryIds, true)) {
            $expandedCategoryIds[] = $cursor->id;
            $cursor = $cursor->parent;
        }
        $favoriteProductIds = app(FavoritesManager::class)->owned($request)
            ->where('entity_type', 'product')->whereNull('trashed_at')
            ->whereIn('entity_id', $products->getCollection()->pluck('id')->all())
            ->pluck('entity_id')->map(fn ($id) => (int) $id)->all();
        $types = ShopProductType::query()->whereHas('products', fn ($q) => $q
            ->where('status', 'published')->whereHas('categories',
                fn ($cats) => $cats->whereIn('shop_categories.id', $activeIds)))
            ->orderBy('name')->get();
        $tags = ShopTag::query()->whereHas('products', fn ($query) => $query
            ->where('status', 'published')->whereHas('categories', fn ($q) => $q
            ->whereIn('shop_categories.id', $activeIds)))->orderBy('name')->get();

        $availableAttributes = [];
        foreach (['color', 'size'] as $code) {
            $availableAttributes[$code] = ShopProductAttributeValue::query()
                ->whereHas('attribute', fn ($q) => $q->where('code', $code))
                ->whereHas('product', fn ($q) => $q->where('status', 'published')
                    ->whereHas('categories', fn ($cat) => $cat->whereIn('shop_categories.id', $activeIds)))
                ->whereNotNull('value')->where('value', '<>', '')
                ->distinct()->orderBy('value')->limit(60)->pluck('value')->all();
        }

        return ThemeResolver::page('shop.index', compact(
            'category', 'categoryNav', 'categoryTree', 'expandedCategoryIds',
            'favoriteProductIds', 'products', 'filters', 'tags', 'types', 'availableAttributes',
        ));
    }

    private function priceFilter(mixed $value): ?int
    {
        if (! is_scalar($value) || ! preg_match('/^\d{1,8}$/', trim((string) $value))) {
            return null;
        }

        return (int) $value;
    }

    /** Flattens the category tree for a consistent sidebar and arbitrary nesting depth. */
    private function categoryNavigation(Collection $categories): array
    {
        $children = $categories->groupBy(fn ($item) => $item->parent_id ?? 0);
        $result = [];
        $visited = [];
        $visit = function (int $parentId, int $depth) use (&$visit, &$result, &$visited, $children): void {
            foreach ($children->get($parentId, collect()) as $node) {
                if (isset($visited[$node->id])) {
                    continue;
                }
                $visited[$node->id] = true;
                $result[] = ['category' => $node, 'depth' => $depth];
                $visit($node->id, $depth + 1);
            }
        };
        $visit(0, 0);

        return $result;
    }

    /** @return array<int, array{category: ShopCategory, children: array}> */
    private function categoryTree(Collection $categories): array
    {
        $children = $categories->groupBy(fn (ShopCategory $item) => (int) ($item->parent_id ?? 0));
        $build = function (int $parentId, array $ancestors = []) use (&$build, $children): array {
            $items = [];
            foreach ($children->get($parentId, collect()) as $item) {
                if (in_array($item->id, $ancestors, true)) {
                    continue;
                }
                $items[] = [
                    'category' => $item,
                    'children' => $build((int) $item->id, [...$ancestors, (int) $item->id]),
                ];
            }

            return $items;
        };

        return $build(0);
    }

    public function product(Request $request, ShopProduct $product, ShopCatalogService $catalog): Response
    {
        $visibleIds = $catalog->visibleCategoryIds();
        abort_unless($product->status === 'published'
            && $product->categories()->whereIn('shop_categories.id', $visibleIds)->exists(), 404);

        $product->load(['categories', 'tags', 'productType', 'attributeValues.attribute']);
        $visibleCategories = $product->categories->whereIn('id', $visibleIds);
        $isFavorite = app(FavoritesManager::class)->status($request, 'product', $product->id) === 'active';
        $breadcrumbs = $visibleCategories->first();

        $allowed = $catalog->attributesForProduct($product)->pluck('id')->all();
        $characteristics = $product->attributeValues
            ->filter(fn ($value) => in_array($value->attribute_id, $allowed, true))
            ->sortBy(fn ($value) => $value->attribute?->sort_order ?? 100)
            ->values();

        return ThemeResolver::page('shop.product', compact(
            'product', 'breadcrumbs', 'characteristics', 'visibleCategories', 'isFavorite',
        ));
    }
}
