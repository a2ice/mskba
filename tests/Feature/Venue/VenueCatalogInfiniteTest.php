<?php

namespace Tests\Feature\Venue;

use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
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

    public function test_map_shows_all_matching_points_and_preserves_filtered_map_view(): void
    {
        foreach (range(1, 6) as $i) {
            $address = Address::factory()->create([
                'latitude' => 55.75 + $i / 1000,
                'longitude' => 37.61 + $i / 1000,
            ]);
            $location = Location::factory()->create(['address_id' => $address->id]);
            Venue::factory()->create([
                'name' => sprintf('Карта %02d', $i),
                'location_id' => $location->id,
                'status' => VenueStatusEnum::CONFIRMED,
            ]);
        }
        Venue::factory()->create([
            'name' => 'Неподходящая площадка',
            'status' => VenueStatusEnum::CONFIRMED,
        ]);

        // Cards still load in chunks of four, while map mode displays all
        // filtered coordinates and no infinite-scroll sentinel.
        $this->get('/venues?search=Карта')->assertOk()
            ->assertSee('Карта 01')
            ->assertDontSee('Карта 06')
            ->assertSee('aria-label="На карте"', false)
            ->assertDontSee('aria-label="Списком"', false);

        $this->get('/venues?view=map&search=Карта')
            ->assertOk()
            ->assertSee('data-venue-catalog-map-frame', false)
            ->assertSee('data-venue-catalog-map-points', false)
            ->assertSee('data-venue-catalog-map', false)
            ->assertSee('name="view" value="map"', false)
            ->assertSee('data-default-category-view="map"', false)
            ->assertSee('Показано')
            // The map payload is embedded as @json, so Cyrillic is Unicode-escaped.
            ->assertSee(json_encode('Карта 01'), false)
            ->assertSee(json_encode('Карта 06'), false)
            ->assertDontSee(json_encode('Неподходящая площадка'), false)
            ->assertDontSee('data-venue-infinite-catalog', false)
            ->assertDontSee('data-venue-sentinel', false)
            ->assertDontSee('aria-label="Списком"', false);
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
