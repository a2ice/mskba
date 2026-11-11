<?php

namespace Tests\Feature\Shop;

use App\Modules\Shop\Domain\Models\ShopCategory;
use App\Modules\Shop\Domain\Models\ShopProduct;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class ShopCatalogControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['themes.active' => 'mskba_app']);
        $this->app->forgetInstance(ThemeResolver::class);
        View::getFinder()->replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $this->withoutVite();
    }

    public function test_catalog_uses_shared_icon_toolbar_select_control_and_dynamic_price_range(): void
    {
        $category = ShopCategory::create([
            'name' => 'Одежда', 'slug' => 'odezhda', 'status' => 'published',
        ]);
        $product = ShopProduct::create([
            'name' => 'Форма баскетбольная', 'slug' => 'basketball-forma', 'status' => 'published',
            'price_kopecks' => 101800, 'stock_status' => 'in_stock',
        ]);
        $product->categories()->attach($category);

        $this->get('/shop')
            ->assertOk()
            ->assertSee('app-catalog-tools__views')
            ->assertSee('app-catalog-tools__sort-menu')
            ->assertSee('app-catalog-tools__reset')
            ->assertSee('aria-label="Карточками"', false)
            ->assertSee('aria-label="Списком"', false)
            ->assertSee('aria-label="Сортировка"', false)
            ->assertSee('data-shop-price-range')
            ->assertSee('data-price-ceiling="1500"', false)
            ->assertSee('data-shop-price-min')
            ->assertSee('data-shop-price-max')
            ->assertSee('class="select-control"', false)
            ->assertDontSee('type="number"')
            ->assertDontSee('Сбросить фильтры</a>');

        $this->get('/shop?view=list&sort=price_desc&min_price=400&max_price=1200')
            ->assertOk()->assertSee('app-shop__products--list')
            ->assertSee('value="400"', false)
            ->assertSee('value="1200"', false)
            ->assertSee('Сначала дороже')
            ->assertDontSee('app-shop__search');

        $this->get('/shop?min_price=1200')->assertOk()
            ->assertDontSee('Форма баскетбольная</a>');
    }

    public function test_venue_catalog_shares_icon_view_controls(): void
    {
        $this->get('/venues?view=list')
            ->assertOk()->assertSee('app-catalog-tools__views')
            ->assertSee('app-venues-catalog__items--list')
            ->assertSee('class="select-control"', false)
            ->assertSee('aria-label="Списком"', false)
            ->assertDontSee('>Карточки</a>', false);
    }
}
