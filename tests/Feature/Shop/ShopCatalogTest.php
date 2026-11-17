<?php

namespace Tests\Feature\Shop;

use App\Filament\Backoffice\Resources\ShopProducts\ShopProductResource;
use App\Modules\Shop\Application\Services\ShopCatalogService;
use App\Modules\Shop\Domain\Models\ShopAttribute;
use App\Modules\Shop\Domain\Models\ShopCategory;
use App\Modules\Shop\Domain\Models\ShopProduct;
use App\Modules\Shop\Domain\Models\ShopProductType;
use App\Modules\Shop\Domain\Models\ShopTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ShopCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_visibility_across_category_tree_and_product_page(): void
    {
        $root = ShopCategory::create(['name' => 'Форма', 'slug' => 'uniform', 'status' => 'published']);
        $child = ShopCategory::create(['name' => 'Майки', 'slug' => 'jerseys', 'status' => 'published', 'parent_id' => $root->id]);
        $hidden = ShopCategory::create(['name' => 'Скрытая ветка', 'slug' => 'hidden', 'status' => 'hidden']);
        $product = ShopProduct::create(['name' => 'Майка MSKBA', 'slug' => 'mskba-jersey', 'status' => 'published', 'stock_status' => 'in_stock', 'stock_quantity' => 5, 'price_kopecks' => 150050, 'old_price_kopecks' => 180000]);
        $product->categories()->attach($child);
        $draft = ShopProduct::create(['name' => 'Не опубликован', 'slug' => 'draft-jersey']);
        $draft->categories()->attach($child);
        $secret = ShopProduct::create(['name' => 'Секретный товар', 'slug' => 'secret', 'status' => 'published']);
        $secret->categories()->attach($hidden);

        $this->get('/shop')->assertOk()->assertSee('Майка MSKBA')->assertDontSee('Секретный товар')->assertDontSee('Не опубликован');
        $this->get('/shop/jerseys')->assertOk()->assertSee('Майка MSKBA');
        $this->get('/shop/uniform')->assertOk()->assertSee('Майка MSKBA');
        $this->get('/shop/p/mskba-jersey')->assertOk()->assertSee('1 500,50')->assertSee('1 800,00')->assertSee('Остаток: 5 шт.');
        $this->get('/shop/p/secret')->assertNotFound();
        $this->get('/shop/hidden')->assertNotFound();

        $root->update(['status' => 'hidden']);
        $this->get('/shop/jerseys')->assertNotFound();
        $this->get('/shop/p/mskba-jersey')->assertNotFound();
    }

    public function test_product_type_defines_characteristics_without_category_or_parent_inheritance(): void
    {
        $root = ShopCategory::create(['name' => 'Одежда', 'slug' => 'clothing']);
        $child = ShopCategory::create(['name' => 'Форма', 'slug' => 'uniform', 'parent_id' => $root->id]);
        $other = ShopCategory::create(['name' => 'Баскетбол', 'slug' => 'basketball']);
        $size = ShopAttribute::create(['name' => 'Размер', 'code' => 'size', 'type' => 'select', 'options' => ['S', 'M', 'L']]);
        $color = ShopAttribute::create(['name' => 'Цвет', 'code' => 'color', 'type' => 'text']);
        $root->attributes()->attach($size);
        $other->attributes()->attach($color);
        $this->assertCount(0, app(ShopCatalogService::class)->attributesForCategoryIds([$child->id]));

        $type = ShopProductType::create(['name' => 'Форма', 'code' => 'uniform']);
        $type->attributes()->attach([$size->id, $color->id]);
        $product = ShopProduct::create([
            'name' => 'Майка', 'slug' => 'jersey', 'product_type_id' => $type->id,
        ]);
        $product->categories()->attach([$child->id, $other->id]);

        $catalog = app(ShopCatalogService::class);
        $this->assertEqualsCanonicalizing([$size->id, $color->id],
            $catalog->attributesForProduct($product)->pluck('id')->all());
        $catalog->syncAttributes($product, [
            ['attribute_id' => $size->id, 'value' => 'L'],
            ['attribute_id' => $color->id, 'value' => 'Синий'],
        ]);
        $this->assertCount(2, $catalog->characteristicRows([$child->id], [], $type->id));
        $this->assertSame('L', $product->attributeValues()->where('attribute_id', $size->id)->value('value'));

        $type->attributes()->detach($color);
        $this->assertCount(1, $catalog->attributesForProduct($product));
        $this->expectException(ValidationException::class);
        $catalog->syncAttributes($product, [['attribute_id' => $color->id, 'value' => 'Красный']]);
    }

    public function test_public_filters_by_type_color_size_stock_price_and_tags(): void
    {
        $category = ShopCategory::create(['name' => 'Одежда', 'slug' => 'clothing', 'status' => 'published']);
        $type = ShopProductType::create(['name' => 'Форма', 'code' => 'uniform']);
        $size = ShopAttribute::create(['name' => 'Размер', 'code' => 'size', 'type' => 'text']);
        $color = ShopAttribute::create(['name' => 'Цвет', 'code' => 'color', 'type' => 'text']);
        $type->attributes()->attach([$size->id, $color->id]);
        $tag = ShopTag::create(['name' => 'Новинка', 'slug' => 'new']);
        $a = ShopProduct::create([
            'name' => 'Чёрная форма', 'slug' => 'black-uniform', 'product_type_id' => $type->id,
            'status' => 'published', 'stock_status' => 'on_request',
            'price_kopecks' => 101800, 'old_price_kopecks' => 265100,
        ]);
        $b = ShopProduct::create(['name' => 'Белая форма', 'slug' => 'white-uniform', 'product_type_id' => $type->id,
            'status' => 'published', 'stock_status' => 'in_stock', 'price_kopecks' => 200000]);
        foreach ([$a, $b] as $product) {
            $product->categories()->attach($category);
        }
        $a->tags()->attach($tag);
        $a->attributeValues()->create(['attribute_id' => $color->id, 'value' => 'Чёрный']);
        $a->attributeValues()->create(['attribute_id' => $size->id, 'value' => '58 RU']);
        $b->attributeValues()->create(['attribute_id' => $color->id, 'value' => 'Белый']);
        $b->attributeValues()->create(['attribute_id' => $size->id, 'value' => 'L']);

        $this->get('/shop?color='.urlencode('Чёрный').'&size=58%20RU&stock=on_request&min_price=1000&max_price=1100&product_type='.$type->id.'&tag=new')
            ->assertOk()->assertSee('Чёрная форма')->assertDontSee('Белая форма');
        $this->get('/shop?color='.urlencode('Белый'))->assertOk()->assertSee('Белая форма')->assertDontSee('Чёрная форма');
        $this->get('/shop?max_price=500')->assertOk()->assertDontSee('Чёрная форма')->assertDontSee('Белая форма');
        $this->get('/shop?view=list')->assertOk()->assertSee('shop-catalog-item--list');
        $this->get('/shop/p/black-uniform')->assertOk()->assertSee('Чёрный')->assertSee('58 RU');
        $this->assertSame(123045, ShopProductResource::kopecks('1230.45'));
    }

    public function test_category_cycle_is_rejected(): void
    {
        $root = ShopCategory::create(['name' => 'Первая', 'slug' => 'first']);
        $child = ShopCategory::create(['name' => 'Вторая', 'slug' => 'second', 'parent_id' => $root->id]);
        $this->expectException(ValidationException::class);
        $root->update(['parent_id' => $child->id]);
    }
}
