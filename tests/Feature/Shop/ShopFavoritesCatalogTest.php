<?php

namespace Tests\Feature\Shop;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shop\Domain\Models\ShopCategory;
use App\Modules\Shop\Domain\Models\ShopProduct;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class ShopFavoritesCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['themes.active' => 'mskba_app']);
        $this->app->forgetInstance(ThemeResolver::class);
        View::getFinder()->replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    public function test_shop_has_sidebar_search_collapsed_categories_create_placeholder_and_real_favorite(): void
    {
        $clothing = ShopCategory::create([
            'name' => 'Одежда', 'slug' => 'odezhda', 'status' => 'published',
        ]);
        $uniform = ShopCategory::create([
            'name' => 'Форма', 'slug' => 'forma', 'status' => 'published', 'parent_id' => $clothing->id,
        ]);
        $product = ShopProduct::create([
            'name' => 'Баскетбольная форма', 'slug' => 'basketball-uniform', 'status' => 'published',
            'stock_status' => 'on_request', 'price_kopecks' => 101800,
        ]);
        $product->categories()->attach($uniform);
        $this->actingAs(User::factory()->create());

        $this->get('/shop')
            ->assertOk()
            ->assertSee('Добавить товар')
            ->assertSee('Поиск товара')
            ->assertSee('data-shop-category-toggle')
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('data-favorite-type="product"', false)
            ->assertSee('data-favorite-id="'.$product->id.'"', false);

        $this->get('/shop/forma')
            ->assertOk()
            ->assertSee('aria-expanded="true"', false)
            ->assertSee('Баскетбольная форма');

        $this->postJson(route('favorites.toggle'), ['type' => 'product', 'id' => $product->id])
            ->assertOk()->assertJsonPath('state', 'active');
        $this->get('/shop')
            ->assertOk()->assertSee('aria-pressed="true"', false);
        $this->get('/shop/p/basketball-uniform')
            ->assertOk()->assertSee('data-favorite-type="product"', false)
            ->assertSee('aria-pressed="true"', false);
        $this->get('/shop/create')
            ->assertOk()->assertSee('Форма добавления товара в разработке');

        $this->getJson(route('favorites.items', ['type' => 'product']))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'product');
    }

    public function test_hidden_product_or_category_cannot_be_newly_favorited(): void
    {
        $category = ShopCategory::create([
            'name' => 'Форма', 'slug' => 'forma', 'status' => 'published',
        ]);
        $product = ShopProduct::create([
            'name' => 'Форма', 'slug' => 'test-product', 'status' => 'published',
        ]);
        $product->categories()->attach($category);

        $category->update(['status' => 'hidden']);
        $this->postJson(route('favorites.toggle'), ['type' => 'product', 'id' => $product->id])
            ->assertNotFound();
        $this->get('/shop/p/test-product')->assertNotFound();
    }
}
