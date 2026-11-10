<?php

namespace Tests\Feature\Shop;

use App\Modules\Shop\Domain\Models\ShopCategory;
use App\Modules\Shop\Domain\Models\ShopProduct;
use App\Modules\Shop\Domain\Models\ShopProductType;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class ShopAppThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The staging site uses mskba_app, while the default PHPUnit theme may
        // be mskba_dark. Verify the exact active-theme pages, not dark fallbacks.
        config(['themes.active' => 'mskba_app']);
        $this->app->forgetInstance(ThemeResolver::class);
        View::getFinder()->replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    public function test_catalog_category_and_product_render_inside_active_app_theme(): void
    {
        $clothing = ShopCategory::create(['name' => 'Одежда', 'slug' => 'odezhda', 'status' => 'published']);
        $uniform = ShopCategory::create([
            'name' => 'Форма', 'slug' => 'forma', 'parent_id' => $clothing->id,
            'status' => 'published',
        ]);
        $type = ShopProductType::create(['name' => 'Баскетбольная форма', 'code' => 'basketball-uniform']);
        $product = ShopProduct::create([
            'name' => 'Форма баскетбольная', 'slug' => 'forma-basketbolnaya',
            'status' => 'published', 'product_type_id' => $type->id,
            'stock_status' => 'on_request', 'price_kopecks' => 101800,
        ]);
        $product->categories()->attach($uniform);

        $this->get('/shop')
            ->assertOk()
            ->assertSee('app-shop__layout')
            ->assertSee('shop-filters-desktop')
            ->assertSee('shop-filters-mobile')
            ->assertSee('Форма баскетбольная')
            ->assertDontSee('Новая тема находится в разработке');

        $this->get('/shop/forma?view=list')
            ->assertOk()
            ->assertSee('app-shop__products--list')
            ->assertSee('Форма баскетбольная');

        $this->get('/shop/p/forma-basketbolnaya')
            ->assertOk()
            ->assertSee('app-shop__product-layout')
            ->assertSee('1 018,00')
            ->assertSee('Наличие уточняется')
            ->assertDontSee('MSKBA App theme');
    }
}
