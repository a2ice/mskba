<?php

namespace Tests\Feature\Shop;

use App\Filament\Backoffice\Resources\ShopProducts\ShopProductResource;
use App\Modules\Shop\Application\Services\ShopCatalogService;
use App\Modules\Shop\Domain\Models\ShopAttribute;
use App\Modules\Shop\Domain\Models\ShopCategory;
use App\Modules\Shop\Domain\Models\ShopProduct;
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

    public function test_multiple_category_inheritance_and_allowed_values(): void
    {
        $root = ShopCategory::create(['name' => 'Одежда', 'slug' => 'clothing']);
        $child = ShopCategory::create(['name' => 'Майки', 'slug' => 'tops', 'parent_id' => $root->id]);
        $other = ShopCategory::create(['name' => 'Баскетбол', 'slug' => 'basketball']);
        $size = ShopAttribute::create(['name' => 'Размер', 'code' => 'size', 'type' => 'select', 'options' => ['S', 'M', 'L']]);
        $color = ShopAttribute::create(['name' => 'Цвет', 'code' => 'color', 'type' => 'text']);
        $root->attributes()->attach($size);
        $other->attributes()->attach([$size->id, $color->id]);
        $product = ShopProduct::create(['name' => 'Майка', 'slug' => 'jersey']);
        $product->categories()->attach([$child->id, $other->id]);
        $catalog = app(ShopCatalogService::class);
        $this->assertEqualsCanonicalizing([$size->id, $color->id], $catalog->attributesForCategoryIds([$child->id, $other->id])->pluck('id')->all());
        $catalog->syncAttributes($product, [['attribute_id' => $size->id, 'value' => 'L'], ['attribute_id' => $color->id, 'value' => 'Синий']]);
        $this->assertSame('L', $product->attributeValues()->where('attribute_id', $size->id)->value('value'));
        $this->assertCount(2, $catalog->characteristicRows([$child->id, $other->id]));
        $this->expectException(ValidationException::class);
        $catalog->syncAttributes($product, [['attribute_id' => $size->id, 'value' => 'XXXL']]);
    }

    public function test_tags_filter_and_prices(): void
    {
        $category = ShopCategory::create(['name' => 'Мячи', 'slug' => 'balls', 'status' => 'published']);
        $tag = ShopTag::create(['name' => 'Новинка', 'slug' => 'new']);
        $category->tags()->attach($tag);
        $a = ShopProduct::create(['name' => 'Баскетбольный мяч', 'slug' => 'orange-ball', 'status' => 'published']);
        $b = ShopProduct::create(['name' => 'Другой мяч', 'slug' => 'second-ball', 'status' => 'published']);
        $a->categories()->attach($category);
        $b->categories()->attach($category);
        $a->tags()->attach($tag);
        $this->get('/shop?tag=new')->assertOk()->assertSee('Баскетбольный мяч')->assertDontSee('Другой мяч');
        $this->assertSame(123045, ShopProductResource::kopecks('1230.45'));
        $this->assertSame(0, ShopProductResource::kopecks('0'));
        $this->assertNull(ShopProductResource::kopecks(null));
    }

    public function test_category_cycle_is_rejected(): void
    {
        $root = ShopCategory::create(['name' => 'Первая', 'slug' => 'first']);
        $child = ShopCategory::create(['name' => 'Вторая', 'slug' => 'second', 'parent_id' => $root->id]);
        $this->expectException(ValidationException::class);
        $root->update(['parent_id' => $child->id]);
    }
}
