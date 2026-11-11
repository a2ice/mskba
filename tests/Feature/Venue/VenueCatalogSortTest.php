<?php

namespace Tests\Feature\Venue;

use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class VenueCatalogSortTest extends TestCase
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

    public function test_sort_options_apply_before_infinite_scroll_pagination(): void
    {
        foreach (['Alpha', 'Gamma', 'Beta', 'Zeta', 'Delta', 'Epsilon'] as $index => $name) {
            Venue::factory()->create([
                'name' => 'SortVenue '.$name,
                'status' => VenueStatusEnum::CONFIRMED,
                'created_at' => now()->subDays(10 - $index),
                'updated_at' => now()->subDays(10 - $index),
            ]);
        }

        $ascending = $this->get('/venues?sort=name_asc')
            ->assertOk()
            ->assertSee('app-catalog-tools__sort-menu')
            ->assertSee('По названию (А–Я)')
            ->assertSee('name="sort"', false)
            ->assertSee('value="name_asc"', false);
        $this->assertStringNotContainsString('SortVenue Zeta', $ascending->getContent());
        $this->assertLessThan(
            strpos($ascending->getContent(), 'SortVenue Beta'),
            strpos($ascending->getContent(), 'SortVenue Alpha'),
        );

        $descending = $this->get('/venues?sort=name_desc&view=list')
            ->assertOk()->assertSee('app-venues-catalog__items--list')
            ->assertSee('SortVenue Zeta')
            ->assertDontSee('SortVenue Alpha');

        $secondPage = $this->getJson('/venues?sort=name_desc&view=list&page=2&fragment=items')
            ->assertOk()->assertJsonPath('has_more', false)
            ->assertJsonPath('total', 6);
        $this->assertStringContainsString('SortVenue Alpha', $secondPage->json('html'));
        $this->assertStringNotContainsString('SortVenue Zeta', $secondPage->json('html'));

        $newest = $this->get('/venues?sort=new')
            ->assertOk()->assertSee('SortVenue Epsilon')
            ->assertDontSee('SortVenue Alpha');
        $this->assertLessThan(
            strpos($newest->getContent(), 'SortVenue Delta'),
            strpos($newest->getContent(), 'SortVenue Epsilon'),
        );
    }

    public function test_unknown_sort_is_rejected_in_json_requests(): void
    {
        $this->getJson('/venues?sort=arbitrary')->assertUnprocessable();
    }
}
