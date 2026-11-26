<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Domain\Models\PageSeoSetting;
use App\Modules\Content\Domain\Models\VenueCatalogLanding;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Enums\VenueCourtEnvironmentEnum;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class VenueCatalogLandingRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_keeps_catalog_unchanged_and_drafts_unavailable(): void
    {
        $city = City::factory()->create();
        $landing = $this->landing($city);
        $this->theme('mskba_app');
        config(['features.venue_catalog_landings.enabled' => false]);
        $this->get(route('venues', ['city_id' => $city->id]))->assertOk()->assertDontSee($landing->h1);
        $this->get($this->url())->assertNotFound();

        config(['features.venue_catalog_landings.enabled' => true]);
        $landing->update(['is_active' => false]);
        $this->get($this->url())->assertNotFound();
        $this->get(route('venues', ['city_id' => $city->id]))->assertOk()->assertDontSee($landing->h1);
    }

    public function test_exact_match_redirects_to_slug_and_uses_same_public_catalog(): void
    {
        $this->theme('mskba_app');
        config(['features.venue_catalog_landings.enabled' => true]);
        $city = City::factory()->create();
        $other = City::factory()->create();
        $landing = $this->landing($city);
        $landing->update([
            'intro_text' => '<p>Unique editorial intro</p>',
            'body_text' => '<h2>Unique editorial body</h2><p><a href="/venues">All venues</a></p>',
        ]);
        PageSeoSetting::query()->create([
            'entity_type' => 'venue_catalog_landing', 'entity_id' => $landing->id,
            'meta_title' => 'Landing title', 'meta_description' => 'Landing description',
        ]);
        foreach (range(1, 5) as $i) {
            $this->venue($city, 'MatchingHall0'.$i);
        }
        $this->venue($other, 'OtherCityHall');
        $this->venue($city, 'HiddenHall', false);

        $this->get(route('venues', ['city_id' => $city->id, 'sort' => 'name_asc']))
            ->assertRedirect($this->url(['sort' => 'name_asc']));
        $this->get($this->url())->assertOk()
            ->assertSee($landing->h1)->assertSee('MatchingHall01')->assertDontSee('MatchingHall05')
            ->assertSee('data-venue-landing-editorial="intro"', false)
            ->assertSee('data-venue-landing-editorial="body"', false)
            ->assertSeeInOrder(['Unique editorial intro', 'MatchingHall01', 'Unique editorial body'])
            ->assertDontSee('OtherCityHall')->assertDontSee('HiddenHall')
            ->assertSee('<title>Landing title</title>', false)
            ->assertSee('name="description" content="Landing description"', false)
            ->assertSee('<link rel="canonical" href="'.$this->url().'">', false)
            ->assertSee('noindex, nofollow, noarchive')
            ->assertSee(e($this->url(['page' => 2])), false);
        $this->get($this->url(['page' => 2]))->assertOk()->assertSee('MatchingHall05')
            ->assertDontSee('Unique editorial intro')->assertDontSee('Unique editorial body')
            ->assertDontSee('MatchingHall01')->assertSee('<link rel="canonical" href="'.$this->url(['page' => 2]).'">', false);
        $this->getJson($this->url(['page' => 2, 'fragment' => 'items']))->assertOk()
            ->assertJsonPath('total', 5)->assertJsonPath('has_more', false);
        $this->get($this->url(['view' => 'map']))->assertOk()->assertSee('MatchingHall05')->assertDontSee('OtherCityHall');

        $this->get(route('venues', ['city_id' => $city->id, 'search' => 'MatchingHall01']))
            ->assertOk()->assertDontSee($landing->h1)
            ->assertDontSee('Unique editorial intro')->assertDontSee('Unique editorial body');
        $this->get($this->url(['city_id' => $other->id]))
            ->assertRedirect(route('venues', ['city_id' => $other->id]));
        $this->get($this->url(['unknown_filter' => '1']))
            ->assertRedirect(route('venues', ['city_id' => $city->id, 'unknown_filter' => '1']));
        $this->get(route('venues', ['city_id' => $city->id, 'unknown_filter' => '1']))
            ->assertOk()->assertDontSee($landing->h1);

        $this->theme('mskba_dark');
        $this->get($this->url())->assertOk()->assertSee($landing->h1)
            ->assertSee('MatchingHall01')->assertDontSee('OtherCityHall')->assertDontSee('HiddenHall')
            ->assertSeeInOrder(['Unique editorial intro', 'MatchingHall01', 'Unique editorial body']);
    }

    public function test_private_preview_of_draft_works_without_public_feature_gate(): void
    {
        $this->theme('mskba_app');
        config(['features.venue_catalog_landings.enabled' => false]);
        $city = City::factory()->create();
        $other = City::factory()->create();
        $landing = $this->landing($city);
        $landing->update(['is_active' => false, 'intro_text' => '<p>Draft editorial intro</p>',
            'body_text' => '<h2>Draft editorial body</h2>']);
        foreach (range(1, 5) as $i) {
            $this->venue($city, 'PreviewHall0'.$i);
        }
        $this->venue($other, 'DifferentCityHall');
        $this->venue($city, 'HiddenPreviewHall', false);
        $preview = fn (array $query = []) => route('admin.content.catalog-landings.preview', ['landingId' => $landing->id] + $query);
        $this->getJson($preview())->assertUnauthorized();
        $editor = User::factory()->create();
        $this->actingAs($editor);
        Gate::define('manage-content', fn (User $user): bool => false);
        $this->get($preview())->assertForbidden();
        Gate::define('manage-content', fn (User $user): bool => true);

        $response = $this->get($preview(['city_id' => $other->id]))->assertOk()
            ->assertSee($landing->h1)->assertSee('Предпросмотр сохранённой посадочной')
            ->assertSeeInOrder(['Draft editorial intro', 'PreviewHall01', 'Draft editorial body'])
            ->assertDontSee('DifferentCityHall')->assertDontSee('HiddenPreviewHall')
            ->assertSee(e($preview(['page' => 2])), false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));

        $this->getJson($preview(['page' => 2, 'fragment' => 'items']))
            ->assertOk()->assertJsonPath('total', 5)->assertJsonPath('has_more', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get($preview(['page' => 2]))->assertOk()->assertSee('PreviewHall05')
            ->assertDontSee('data-venue-landing-editorial="intro"', false)
            ->assertDontSee('data-venue-landing-editorial="body"', false)
            ->assertDontSee('<p>Draft editorial intro</p>', false)
            ->assertDontSee('Draft editorial body')
            ->assertSee('name="description" content="Draft editorial intro"', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get($this->url())->assertNotFound();
        $this->get(route('venues', ['city_id' => $city->id]))->assertOk()
            ->assertDontSee('Draft editorial intro');
        $this->assertFalse($landing->fresh()->is_active);

        $this->theme('mskba_dark');
        $this->get($preview())->assertOk()->assertSeeInOrder(['Draft editorial intro', 'PreviewHall01', 'Draft editorial body'])
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_multi_type_catalogue_unions_discovery_branches_without_duplicates(): void
    {
        $this->theme('mskba_app');
        config(['features.venue_catalog_landings.enabled' => true]);
        $city = City::factory()->create();
        $make = function (string $name, VenueTypeEnum $type, VenueCourtEnvironmentEnum $environment) use ($city): Venue {
            $address = Address::factory()->create(['city_id' => $city->id, 'latitude' => 55.75, 'longitude' => 37.62]);
            $location = Location::factory()->create(['address_id' => $address->id]);
            $venue = Venue::factory()->create([
                'name' => $name, 'type' => $type, 'location_id' => $location->id,
                'status' => VenueStatusEnum::CONFIRMED, 'is_published' => true,
                'operational_status' => VenueOperationalStatusEnum::ACTIVE,
            ]);
            $venue->primaryCourt()->firstOrFail()->update([
                'environment' => $environment, 'operational_status' => VenueOperationalStatusEnum::ACTIVE,
            ]);

            return $venue;
        };
        $make('IndoorSchoolMatch', VenueTypeEnum::SCHOOL, VenueCourtEnvironmentEnum::INDOOR);
        $make('IndoorComplexMatch', VenueTypeEnum::SPORTS_COMPLEX, VenueCourtEnvironmentEnum::INDOOR);
        $make('OutdoorComplexMatch', VenueTypeEnum::SPORTS_COMPLEX, VenueCourtEnvironmentEnum::OUTDOOR);
        $make('OutdoorSchoolExcluded', VenueTypeEnum::SCHOOL, VenueCourtEnvironmentEnum::OUTDOOR);
        $filters = ['city_id' => $city->id, 'type' => ['sports_hall', 'sports_complex']];
        $landing = $this->landing($city);
        $landing->update(['type' => $filters['type']]);

        $this->get(route('venues', $filters))->assertRedirect($this->url());
        $this->get($this->url())->assertOk()
            ->assertSee('IndoorSchoolMatch')->assertSee('IndoorComplexMatch')->assertSee('OutdoorComplexMatch')
            ->assertDontSee('OutdoorSchoolExcluded')
            ->assertSee('data-context-result-total="3"', false);
        $this->getJson($this->url(['fragment' => 'items']))->assertOk()->assertJsonPath('total', 3);
        $this->get($this->url(['view' => 'map']))->assertOk()->assertSee('data-context-result-total="3"', false);
        $this->getJson(route('venues', ['city_id' => $city->id, 'type' => 'sports_hall', 'fragment' => 'items']))
            ->assertOk()->assertJsonPath('total', 2);
        $this->getJson(route('venues', ['city_id' => $city->id, 'fragment' => 'items']))
            ->assertOk()->assertJsonPath('total', 4);
        $this->getJson(route('venues', ['type' => ['sports_hall', 'invalid'], 'fragment' => 'items']))
            ->assertUnprocessable()->assertJsonValidationErrors('type.1');

        $this->theme('mskba_dark');
        $this->get($this->url())->assertOk()->assertSee('IndoorSchoolMatch')->assertSee('OutdoorComplexMatch')
            ->assertDontSee('OutdoorSchoolExcluded')->assertSee('name="type[]"', false);
    }

    public function test_related_links_use_current_public_slugs_and_never_expose_drafts(): void
    {
        $this->theme('mskba_app');
        config(['features.venue_catalog_landings.enabled' => true]);
        $city = City::factory()->create();
        $landing = $this->landing($city);
        $target = VenueCatalogLanding::query()->create([
            'name' => 'Related target', 'h1' => 'Unique related heading',
            'slug' => 'rent/related', 'city_id' => City::factory()->create()->id, 'is_active' => true,
        ]);
        $draft = VenueCatalogLanding::query()->create([
            'name' => 'Draft target', 'h1' => 'Private related heading',
            'slug' => 'rent/secret', 'city_id' => City::factory()->create()->id, 'is_active' => false,
        ]);
        $landing->update(['related_landing_ids' => [$target->id, $draft->id, $landing->id, $target->id]]);
        $target->update(['slug' => 'rent/renamed']);
        $url = route('venues.collections.show', ['landingSlug' => $target->slug]);

        $this->get($this->url())->assertOk()
            ->assertSee('data-venue-landing-related', false)
            ->assertSee('href="'.$url.'"', false)->assertSee('Unique related heading')
            ->assertDontSee('Private related heading')->assertDontSee('/venues/collections/rent/related');
        $this->get($this->url(['page' => 2]))->assertOk()->assertDontSee('data-venue-landing-related', false);
        $this->get(route('venues'))->assertOk()->assertDontSee('data-venue-landing-related', false);

        $this->theme('mskba_dark');
        $this->get($this->url())->assertOk()->assertSee('href="'.$url.'"', false)->assertDontSee('Private related heading');
        $target->update(['is_active' => false]);
        $this->get($this->url())->assertOk()->assertDontSee('data-venue-landing-related', false);
    }

    public function test_indexing_and_sitemap_remain_disabled_even_when_editor_flags_are_enabled(): void
    {
        config(['features.venue_catalog_landings.enabled' => true]);
        $city = City::factory()->create();
        $landing = $this->landing($city);
        $landing->update([
            'allow_indexing' => true, 'include_in_sitemap' => true,
            'intro_text' => '<p>Introduction</p>', 'body_text' => '<p>Body</p>',
        ]);
        $this->venue($city, 'EligibleHall');
        foreach (['mskba_app', 'mskba_dark'] as $theme) {
            $this->theme($theme);
            foreach (['mskba.ru', 'dev.mskba.ru'] as $host) {
                $base = 'https://'.$host;
                $url = $base.'/venues/collections/rent/test';
                $response = $this->get($url)->assertOk()
                    ->assertSee('name="robots" content="noindex, nofollow, noarchive"', false)
                    ->assertSee($landing->h1)->assertSee('EligibleHall');
                if ($host === 'dev.mskba.ru') {
                    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
                }
                $this->get($base.'/sitemap.xml')->assertOk()->assertDontSee('/venues/collections/rent/test', false);
            }
        }
    }

    public function test_existing_venue_and_court_routes_are_not_captured(): void
    {
        $this->assertSame('venues.show', Route::getRoutes()->match(Request::create('/venues/collections'))->getName());
        $this->assertSame('venues.courts.show', Route::getRoutes()->match(Request::create('/venues/collections/courts/test'))->getName());
        $this->assertSame('venues.preview', Route::getRoutes()->match(Request::create('/venues/collections/preview'))->getName());
        $this->assertSame('venues.nearby-map', Route::getRoutes()->match(Request::create('/venues/collections/nearby-map'))->getName());
        $this->assertSame('venues.collections.show', Route::getRoutes()->match(Request::create('/venues/collections/rent/test'))->getName());
    }

    private function theme(string $theme): void
    {
        $this->withoutVite();
        config(['themes.active' => $theme]);
        $this->app->forgetInstance(ThemeResolver::class);
        View::getFinder()->replaceNamespace('theme', resource_path('themes/'.$theme.'/views'));
        View::getFinder()->flush();
    }

    private function landing(City $city): VenueCatalogLanding
    {
        return VenueCatalogLanding::query()->create([
            'name' => 'Landing test', 'h1' => 'Unique landing heading',
            'slug' => 'rent/test', 'city_id' => $city->id, 'is_active' => true,
        ]);
    }

    private function venue(City $city, string $name, bool $published = true): void
    {
        $address = Address::factory()->create(['city_id' => $city->id, 'latitude' => 55.75, 'longitude' => 37.62]);
        $location = Location::factory()->create(['address_id' => $address->id]);
        Venue::factory()->create([
            'name' => $name, 'location_id' => $location->id,
            'status' => VenueStatusEnum::CONFIRMED, 'is_published' => $published,
        ]);
    }

    private function url(array $query = []): string
    {
        return route('venues.collections.show', ['landingSlug' => 'rent/test'] + $query);
    }
}
