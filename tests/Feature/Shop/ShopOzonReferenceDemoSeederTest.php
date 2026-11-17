<?php

namespace Tests\Feature\Shop;

use App\Modules\Shop\Application\Services\ShopCatalogService;
use App\Modules\Shop\Domain\Models\ShopCategory;
use App\Modules\Shop\Domain\Models\ShopProduct;
use Database\Seeders\ShopOzonReferenceDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ShopOzonReferenceDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_realistic_public_reference_item_and_type_based_characteristics(): void
    {
        Storage::fake('public');
        $this->seed(ShopOzonReferenceDemoSeeder::class);

        $parent = ShopCategory::query()->where('slug', 'odezhda')->firstOrFail();
        $uniform = ShopCategory::query()->where('slug', 'forma')->firstOrFail();
        $this->assertSame($parent->id, $uniform->parent_id);
        $this->assertNotNull($uniform->default_product_type_id);

        $product = ShopProduct::query()->where('slug', 'forma-basketbolnaya-ozon-5041196075')->firstOrFail();
        $this->assertSame('published', $product->status);
        $this->assertSame('on_request', $product->stock_status);
        $this->assertSame(0, $product->stock_quantity);
        $this->assertSame(101800, $product->price_kopecks);
        $this->assertSame(265100, $product->old_price_kopecks);
        $this->assertSame($uniform->default_product_type_id, $product->product_type_id);
        $this->assertTrue($product->categories()->whereKey($uniform->id)->exists());

        $attributes = app(ShopCatalogService::class)->attributesForProduct($product)->pluck('code')->all();
        foreach (['color', 'size', 'gender', 'material', 'kit-contents', 'sleeve-type', 'season', 'sport'] as $code) {
            $this->assertContains($code, $attributes);
        }

        $this->assertSame('Чёрный', $product->attributeValues()->whereHas('attribute',
            fn ($query) => $query->where('code', 'color'))->value('value'));
        $this->assertSame('58 RU', $product->attributeValues()->whereHas('attribute',
            fn ($query) => $query->where('code', 'size'))->value('value'));
        $this->assertSame('Мужской, Женский', $product->attributeValues()->whereHas('attribute',
            fn ($query) => $query->where('code', 'gender'))->value('value'));
        $this->assertSame(['shop/products/ozon-5041196075.webp'], $product->images);
        Storage::disk('public')->assertExists('shop/products/ozon-5041196075.webp');
        $this->get('/shop/p/forma-basketbolnaya-ozon-5041196075')
            ->assertOk()->assertSee('1 018,00')->assertSee('2 651,00');
        $this->get('/shop/forma')->assertOk()->assertSee('Форма баскетбольная');
    }

    public function test_repeated_deploy_does_not_replace_editor_changes_or_create_duplicates(): void
    {
        Storage::fake('public');
        $this->seed(ShopOzonReferenceDemoSeeder::class);

        $product = ShopProduct::query()->where('sku', 'OZON-REF-5041196075')->firstOrFail();
        $product->update([
            'name' => 'Уточнённое название из Ozon',
            'status' => 'published', 'price_kopecks' => 199900,
            'stock_status' => 'in_stock', 'stock_quantity' => 3,
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
