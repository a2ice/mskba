<?php

namespace Tests\Feature\Venue;

use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
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
            ->assertSee('Название')
            ->assertSee('Рейтинг')
            ->assertSee('По убыванию')
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

        $this->get('/venues?sort=old')->assertOk()->assertSeeInOrder(['SortVenue Alpha', 'SortVenue Gamma', 'SortVenue Beta', 'SortVenue Zeta']);

        $newest = $this->get('/venues?sort=new')
            ->assertOk()->assertSee('SortVenue Epsilon')
            ->assertDontSee('SortVenue Alpha');
        $this->assertLessThan(
            strpos($newest->getContent(), 'SortVenue Delta'),
            strpos($newest->getContent(), 'SortVenue Epsilon'),
        );
    }

    public function test_distance_order_applies_before_pagination_without_radius(): void
    {
        foreach (['Far' => 60, 'Mid' => 56, 'Near' => 55.8, 'Closest' => 55.71, 'Second' => 55.72, 'Unknown' => null] as $name => $latitude) {
            $address = Address::factory()->create(['latitude' => $latitude, 'longitude' => $latitude === null ? null : 37.6]);
            $location = Location::factory()->create(['address_id' => $address->id]);
            Venue::factory()->create([
                'name' => 'DistanceVenue '.$name,
                'status' => VenueStatusEnum::CONFIRMED,
                'location_id' => $location->id,
            ]);
        }

        $this->postJson(route('venues.distance-location'), [
            'action' => 'locate', 'latitude' => 55.7, 'longitude' => 37.6,
        ])->assertOk()->assertJson(['available' => true, 'answered' => false]);

        $firstPage = $this->get('/venues?sort=distance')
            ->assertOk()->assertSeeInOrder(['DistanceVenue Closest', 'DistanceVenue Second', 'DistanceVenue Near', 'DistanceVenue Mid'])
            ->assertDontSee('DistanceVenue Far')->assertDontSee('DistanceVenue Unknown');
        $this->assertStringContainsString('private', $firstPage->headers->get('Cache-Control'));

        $this->getJson('/venues?sort=distance&page=2&fragment=items')
            ->assertOk()->assertJsonPath('total', 6)->assertJsonPath('has_more', false)
            ->assertSeeInOrder(['DistanceVenue Far', 'DistanceVenue Unknown']);

        $this->get('/venues?sort=distance_desc')->assertOk()->assertSeeInOrder(['DistanceVenue Far', 'DistanceVenue Mid', 'DistanceVenue Near', 'DistanceVenue Second'])->assertDontSee('DistanceVenue Unknown');
        $this->getJson('/venues?sort=distance_desc&page=2&fragment=items')->assertOk()->assertSeeInOrder(['DistanceVenue Closest', 'DistanceVenue Unknown']);

        $this->postJson(route('venues.distance-location'), ['action' => 'answer'])
            ->assertOk()->assertJson(['answered' => true]);
        $this->postJson(route('venues.distance-location'), ['action' => 'forget'])
            ->assertOk()->assertJson(['available' => false, 'answered' => true]);
    }

    public function test_account_answer_survives_session_change_but_coordinates_do_not(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $endpoint = route('venues.distance-location');
        $this->postJson($endpoint, ['action' => 'locate', 'latitude' => 95, 'longitude' => 37.6])
            ->assertUnprocessable();
        $this->postJson($endpoint, ['action' => 'locate', 'latitude' => 55.7, 'longitude' => 37.6])
            ->assertOk()->assertJson(['answered' => false]);
        $this->postJson($endpoint, ['action' => 'answer'])->assertOk()->assertJson(['answered' => true]);
        $this->assertTrue($user->fresh()->venue_distance_sort_prompt_answered);
        $this->app['session']->flush();
        $this->postJson($endpoint, ['action' => 'locate', 'latitude' => 56, 'longitude' => 38])
            ->assertOk()->assertJson(['answered' => true]);
    }

    public function test_distance_option_is_available_without_permission_and_new_point_replaces_old_origin(): void
    {
        $this->get('/venues?sort=new')->assertOk()
            ->assertSee('data-venue-distance-option', false)
            ->assertSee('Удалённость')
            ->assertDontSee('data-venue-distance-template', false)
            ->assertSee('data-auto-prompt="0"', false);

        foreach (['West' => 37.1, 'East' => 38.1] as $name => $longitude) {
            $address = Address::factory()->create(['latitude' => 55.7, 'longitude' => $longitude]);
            $location = Location::factory()->create(['address_id' => $address->id]);
            Venue::factory()->create([
                'name' => 'ReplaceOrigin '.$name,
                'status' => VenueStatusEnum::CONFIRMED,
                'location_id' => $location->id,
            ]);
        }
        $this->postJson(route('venues.distance-location'), [
            'action' => 'locate', 'latitude' => 55.7, 'longitude' => 37.1,
        ])->assertOk();
        $this->get('/venues?sort=distance')->assertOk()->assertSeeInOrder(['ReplaceOrigin West', 'ReplaceOrigin East']);
        $this->postJson(route('venues.distance-location'), [
            'action' => 'locate', 'latitude' => 55.7, 'longitude' => 38.1,
        ])->assertOk();
        $this->get('/venues?sort=distance')->assertOk()->assertSeeInOrder(['ReplaceOrigin East', 'ReplaceOrigin West']);
    }

    public function test_published_rating_and_both_directions_precede_pagination(): void
    {
        $scores = ['Alpha' => [1], 'Beta' => [2, 3], 'Gamma' => [3], 'Delta' => [4], 'Epsilon' => [5], 'Unknown' => []];
        foreach ($scores as $name => $ratings) {
            $venue = Venue::factory()->create(['name' => 'RatedVenue '.$name, 'status' => VenueStatusEnum::CONFIRMED]);
            foreach ($ratings as $rating) {
                \App\Modules\Venue\Domain\Models\VenueReview::factory()->for($venue)->create(['rating' => $rating, 'is_published' => true]);
            }
            \App\Modules\Venue\Domain\Models\VenueReview::factory()->for($venue)->create(['rating' => 5, 'is_published' => false, 'published_at' => null]);
        }
        $this->get('/venues?sort=rating_asc')->assertOk()
            ->assertSeeInOrder(['RatedVenue Alpha', 'RatedVenue Beta', 'RatedVenue Gamma', 'RatedVenue Delta'])
            ->assertSee('2.5')->assertSee('data-venue-card-rating', false)->assertDontSee('RatedVenue Unknown');
        $this->get('/venues?sort=rating_desc')->assertOk()
            ->assertSeeInOrder(['RatedVenue Epsilon', 'RatedVenue Delta', 'RatedVenue Gamma', 'RatedVenue Beta'])
            ->assertDontSee('RatedVenue Unknown');
        $this->getJson('/venues?sort=rating_desc&page=2&fragment=items')->assertOk()
            ->assertJsonPath('total', 6)->assertJsonPath('has_more', false)
            ->assertSeeInOrder(['RatedVenue Alpha', 'RatedVenue Unknown']);
    }

    public function test_unknown_sort_is_rejected_in_json_requests(): void
    {
        $this->getJson('/venues?sort=arbitrary')->assertUnprocessable();
    }
}
