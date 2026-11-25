<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\Services\VenueCatalogLandingResolver;
use App\Modules\Content\Domain\Models\VenueCatalogLanding;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class VenueCatalogLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_exact_active_semantic_combinations_resolve(): void
    {
        $city = City::factory()->create();
        $district = District::factory()->for($city)->create();
        $landing = $this->landing([
            'city_id' => $city->id, 'district_id' => $district->id,
            'type' => 'sports_hall', 'rental_configured' => true,
        ]);
        $resolver = app(VenueCatalogLandingResolver::class);
        $filters = ['rental_configured' => '1', 'district_id' => (string) $district->id,
            'city_id' => (string) $city->id, 'type' => 'sports_hall'];

        $this->assertNull($resolver->forSlug($landing->slug));
        $this->assertNull($resolver->forCatalog($filters));
        $this->assertFalse($landing->allow_indexing);
        $this->assertFalse($landing->include_in_sitemap);

        $landing->update(['is_active' => true]);
        $this->assertTrue($landing->is($resolver->forSlug($landing->slug)));
        $this->assertTrue($landing->is($resolver->forCatalog($filters + [
            'view' => 'map', 'page' => '2', 'sort' => 'new', 'fragment' => 'items',
            'search' => '', 'access' => '', 'my_interests' => '0',
        ])));
        foreach ([
            ['access' => 'paid'], ['search' => 'зал'], ['my_interests' => '1'],
            ['has_upcoming_events' => '1'], ['latitude' => 0, 'longitude' => 0, 'radius_km' => 3],
            ['unknown_filter' => '1'],
        ] as $extra) {
            $this->assertNull($resolver->forCatalog($filters + $extra));
        }
        $this->assertNull($resolver->forCatalog(array_replace($filters, ['rental_configured' => '0'])));
        $this->assertNull($resolver->forCatalog(array_diff_key($filters, ['district_id' => true])));
        $this->assertNull($resolver->forSlug('not-found'));
        $landing->update(['is_active' => false]);
        $this->assertNull($resolver->forCatalog($filters));
    }

    public function test_database_prevents_duplicate_active_combinations_but_allows_drafts(): void
    {
        $first = $this->landing(['is_active' => true]);
        $draft = $this->landing(['slug' => 'rent/another']);
        $this->assertNull($draft->active_filter_signature);
        $this->assertNotNull($first->active_filter_signature);

        $this->expectException(QueryException::class);
        $draft->update(['is_active' => true]);
    }

    public function test_district_must_belong_to_selected_city(): void
    {
        $city = City::factory()->create();
        $district = District::factory()->for(City::factory()->create())->create();
        $this->expectException(InvalidArgumentException::class);
        $this->landing(['city_id' => $city->id, 'district_id' => $district->id]);
    }

    public function test_slug_is_unique_even_for_drafts(): void
    {
        $this->landing();
        $this->expectException(QueryException::class);
        $this->landing(['type' => 'street_court']);
    }

    public function test_unfiltered_landing_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->landing(['type' => null]);
    }

    public function test_unsafe_slug_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->landing(['slug' => '../rent']);
    }

    private function landing(array $attributes = []): VenueCatalogLanding
    {
        return VenueCatalogLanding::query()->create(array_replace([
            'name' => 'Test landing', 'slug' => 'rent/test',
            'h1' => 'Test landing', 'type' => 'sports_hall',
        ], $attributes));
    }
}
