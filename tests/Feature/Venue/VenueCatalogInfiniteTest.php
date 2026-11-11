<?php

namespace Tests\Feature\Venue;

use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class VenueCatalogInfiniteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['themes.active' => 'mskba_app']);
        $this->app->forgetInstance(ThemeResolver::class);
        View::getFinder()->replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    public function test_app_catalog_uses_infinite_chucks_and_stable_list_view(): void
    {
        foreach (range(1, 6) as $i) {
            Venue::factory()->create([
                'name' => sprintf('Тестовая площадка %02d', $i),
                'status' => VenueStatusEnum::CONFIRMED,
            ]);
        }

        $this->get('/venues')
            ->assertOk()
            ->assertSee('data-venue-infinite-catalog')
            ->assertSee('data-next-page="2"', false)
            ->assertSee('image-upload-loading__spinner')
            ->assertSee('Тестовая площадка 01')
            ->assertDontSee('Тестовая площадка 06')
            ->assertDontSee('БАСКЕТБОЛЬНЫЕ ПЛОЩАДКИ');

        $response = $this->getJson('/venues?page=2&fragment=items')
            ->assertOk()->assertJsonPath('has_more', false)
            ->assertJsonPath('next_page', null)
            ->assertJsonPath('total', 6);
        $this->assertStringContainsString('Тестовая площадка 06', $response->json('html'));
        $this->assertStringNotContainsString('Тестовая площадка 01', $response->json('html'));

        $this->get('/venues?view=list')
            ->assertOk()->assertSee('app-venues-catalog__items--list');

        $this->getJson('/venues?page=2&fragment=items&search=unlikely-test-value')
            ->assertOk()->assertJsonPath('total', 0)->assertJsonPath('has_more', false);
    }
}
