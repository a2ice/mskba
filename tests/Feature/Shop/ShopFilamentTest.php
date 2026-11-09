<?php

namespace Tests\Feature\Shop;

use App\Filament\Backoffice\Resources\ShopAttributes\Pages\CreateShopAttribute;
use App\Filament\Backoffice\Resources\ShopCategories\Pages\CreateShopCategory;
use App\Filament\Backoffice\Resources\ShopProducts\Pages\CreateShopProduct;
use App\Filament\Backoffice\Resources\ShopProducts\Pages\EditShopProduct;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shop\Domain\Models\ShopAttribute;
use App\Modules\Shop\Domain\Models\ShopCategory;
use App\Modules\Shop\Domain\Models\ShopProduct;
use App\Modules\Shop\Domain\Models\ShopTag;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ShopFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_backoffice_creates_category_and_product_with_inherited_characteristics(): void
    {
        $admin = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $admin->createProfile([]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(CreateShopAttribute::class)->fillForm([
            'name' => 'Размер', 'code' => 'size', 'type' => 'select',
            'options' => ['M', 'L'], 'sort_order' => 100,
        ])->call('create')->assertHasNoFormErrors();
        $attribute = ShopAttribute::query()->where('code', 'size')->firstOrFail();

        $tag = ShopTag::create(['name' => 'Новинка', 'slug' => 'new']);
        Livewire::test(CreateShopCategory::class)->fillForm([
            'name' => 'Форма', 'slug' => 'uniforms', 'status' => 'published',
            'sort_order' => 100, 'tag_ids' => [$tag->id], 'attribute_ids' => [$attribute->id],
        ])->call('create')->assertHasNoFormErrors();
        $category = ShopCategory::query()->where('slug', 'uniforms')->firstOrFail();
        $this->assertTrue($category->attributes()->whereKey($attribute->id)->exists());
        $this->assertTrue($category->tags()->whereKey($tag->id)->exists());

        Livewire::test(CreateShopProduct::class)->fillForm([
            'name' => 'Майка', 'slug' => 'jersey', 'sku' => 'MS-001',
            'status' => 'published', 'stock_status' => 'in_stock', 'stock_quantity' => 12,
            'sort_order' => 100, 'category_ids' => [$category->id], 'tag_ids' => [$tag->id],
            'price_rub' => '1999.99', 'old_price_rub' => '2499.99',
            'characteristics' => [['attribute_id' => $attribute->id, 'value' => 'M']],
        ])->call('create')->assertHasNoFormErrors();

        $product = ShopProduct::query()->where('sku', 'MS-001')->firstOrFail();
        $this->assertSame(199999, $product->price_kopecks);
        $this->assertSame(249999, $product->old_price_kopecks);
        $this->assertSame(12, $product->stock_quantity);
        $this->assertTrue($product->categories()->whereKey($category->id)->exists());
        $this->assertTrue($product->tags()->whereKey($tag->id)->exists());
        $this->assertSame('M', $product->attributeValues()->where('attribute_id', $attribute->id)->value('value'));
    }

    public function test_backoffice_can_edit_a_product_and_replace_its_categories_and_characteristics(): void
    {
        $admin = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $admin->createProfile([]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $category = ShopCategory::create(['name' => 'Одежда', 'slug' => 'clothing', 'status' => 'published']);
        $another = ShopCategory::create(['name' => 'Обувь', 'slug' => 'shoes', 'status' => 'published']);
        $size = ShopAttribute::create([
            'name' => 'Размер', 'code' => 'size', 'type' => 'select', 'options' => ['M', 'L'],
        ]);
        $another->attributes()->attach($size);
        $product = ShopProduct::create([
            'name' => 'Майка', 'slug' => 'jersey', 'status' => 'published',
            'stock_status' => 'out_of_stock', 'stock_quantity' => 0, 'price_kopecks' => 10000,
        ]);
        $product->categories()->attach($category);

        Livewire::test(EditShopProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'name' => 'Новая майка',
                'category_ids' => [$another->id],
                'price_rub' => '450.50',
                'old_price_rub' => '600',
                'stock_status' => 'in_stock',
                'stock_quantity' => 15,
                'characteristics' => [['attribute_id' => $size->id, 'value' => 'L']],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame('Новая майка', $product->name);
        $this->assertSame(45050, $product->price_kopecks);
        $this->assertSame(60000, $product->old_price_kopecks);
        $this->assertSame(15, $product->stock_quantity);
        $this->assertEqualsCanonicalizing([$another->id], $product->categories()->pluck('shop_categories.id')->all());
        $this->assertSame('L', $product->attributeValues()->where('attribute_id', $size->id)->value('value'));
    }
}
