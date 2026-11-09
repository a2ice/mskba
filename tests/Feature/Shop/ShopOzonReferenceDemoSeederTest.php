<?php

namespace Tests\Feature\Shop;

use App\Modules\Shop\Application\Services\ShopCatalogService;
use App\Modules\Shop\Domain\Models\ShopCategory;
use App\Modules\Shop\Domain\Models\ShopProduct;
use Database\Seeders\ShopOzonReferenceDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShopOzonReferenceDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_nested_categories_attributes_and_unpublished_reference_item(): void
    {
        $this->seed(ShopOzonReferenceDemoSeeder::class);

        $parent = ShopCategory::query()->where('slug', 'odezhda')->firstOrFail();
        $uniform = ShopCategory::query()->where('slug', 'forma')->firstOrFail();
        $this->assertSame($parent->id, $uniform->parent_id);

        $attributes = app(ShopCatalogService::class)
            ->attributesForCategoryIds([$uniform->id])->pluck('code')->all();
        foreach (['color', 'size', 'gender', 'brand', 'material', 'material-composition', 'kit-contents', 'sleeve-type', 'season', 'height', 'manufacturer-size', 'country-of-origin', 'sport'] as $code) {
            $this->assertContains($code, $attributes);
        }

        $product = ShopProduct::query()->where('slug', 'forma-basketbolnaya-ozon-5041196075')->firstOrFail();
        $this->assertSame('draft', $product->status);
        $this->assertSame('out_of_stock', $product->stock_status);
        $this->assertSame(0, $product->stock_quantity);
        $this->assertNull($product->price_kopecks);
        $this->assertSame([], $product->images);
        $this->assertTrue($product->categories()->whereKey($uniform->id)->exists());
        $this->assertSame('Баскетбол', $product->attributeValues()->whereHas('attribute',
            fn ($query) => $query->where('code', 'sport'))->value('value'));
        $this->get('/shop/p/forma-basketbolnaya-ozon-5041196075')->assertNotFound();
        $this->get('/shop/forma')->assertOk();
    }

    public function test_repeated_deploy_does_not_replace_editor_changes_or_create_duplicates(): void
    {
        $this->seed(ShopOzonReferenceDemoSeeder::class);

        $product = ShopProduct::query()->where('sku', 'OZON-REF-5041196075')->firstOrFail();
        $product->update([
            'name' => 'Уточнённое название из Ozon',
            'status' => 'published',
            'price_kopecks' => 199900,
            'stock_status' => 'in_stock',
            'stock_quantity' => 3,
        ]);
        $category = ShopCategory::query()->where('slug', 'forma')->firstOrFail();
        $category->update(['name' => 'Баскетбольная форма']);

        $this->seed(ShopOzonReferenceDemoSeeder::class);

        $this->assertSame(1, ShopProduct::query()->where('sku', 'OZON-REF-5041196075')->count());
        $this->assertSame('Уточнённое название из Ozon', $product->fresh()->name);
        $this->assertSame(199900, $product->fresh()->price_kopecks);
        $this->assertSame('published', $product->fresh()->status);
        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertSame('Баскетбольная форма', $category->fresh()->name);
    }
}
