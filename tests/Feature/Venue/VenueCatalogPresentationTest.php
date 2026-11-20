<?php

namespace Tests\Feature\Venue;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Application\UseCases\ListVenuesHandler;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueCatalogPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_category_catalog_uses_breadcrumbs_sidebar_accordion_and_icon_only_create_action(): void
    {
        $this
            ->get(route('venues'))
            ->assertOk()
            ->assertSee('class="page-breadcrumbs"', false)
            ->assertSee('data-default-category-sidebar-accordion', false)
            ->assertSee('aria-controls="venues-sidebar-navigation"', false)
            ->assertSee('aria-controls="venues-sidebar-filters"', false)
            ->assertSee('default-category-toolbar__action-button', false)
            ->assertSee('title="Добавить площадку"', false)
            ->assertDontSee('catalog-toolbar__button-text">Добавить', false);
    }
    public function test_upcoming_event_filter_excludes_private_draft_cancelled_and_past_events(): void
    {
        $this->freezeTime();
        $make = function (int $days, EventStatusEnum $status, EventVisibilityEnum $visibility): Venue {
            $venue = Venue::factory()->create([
                'status' => VenueStatusEnum::CONFIRMED,
                'is_published' => true,
            ]);
            Event::factory()->create([
                'venue_id' => $venue->id,
                'status' => $status,
                'visibility' => $visibility,
                'starts_at' => now()->addDays($days),
                'ends_at' => now()->addDays($days)->addHour(),
            ]);

            return $venue;
        };

        $soon = $make(1, EventStatusEnum::PUBLISHED, EventVisibilityEnum::PUBLIC);
        $later = $make(10, EventStatusEnum::PUBLISHED, EventVisibilityEnum::PUBLIC);
        $make(1, EventStatusEnum::PUBLISHED, EventVisibilityEnum::PRIVATE);
        $make(1, EventStatusEnum::DRAFT, EventVisibilityEnum::PUBLIC);
        $make(1, EventStatusEnum::CANCELLED, EventVisibilityEnum::PUBLIC);
        $make(-1, EventStatusEnum::PUBLISHED, EventVisibilityEnum::PUBLIC);

        $handler = app(ListVenuesHandler::class);
        $this->assertEqualsCanonicalizing([$soon->id], collect($handler->handle(null, filters: [
            'has_upcoming_events' => true, 'event_days' => 1,
        ]))->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$soon->id, $later->id], collect($handler->handle(null, filters: [
            'has_upcoming_events' => true, 'event_days' => 14,
        ]))->pluck('id')->all());
        $this->getJson(route('venues', ['has_upcoming_events' => 1, 'event_days' => 15]))
            ->assertUnprocessable()->assertJsonValidationErrors('event_days');
    }

    public function test_catalog_radius_filters_all_visible_venues_before_pagination(): void
    {
        $make = function (string $name, ?float $latitude, ?float $longitude, bool $published = true): Venue {
            $address = Address::factory()->create(['latitude' => $latitude, 'longitude' => $longitude]);
            $location = Location::factory()->create(['address_id' => $address->id]);

            return Venue::factory()->create([
                'name' => $name, 'location_id' => $location->id,
                'status' => VenueStatusEnum::CONFIRMED, 'is_published' => $published,
            ]);
        };
        foreach (range(1, 6) as $index) {
            $make(sprintf('AA Far %02d', $index), 55.95, 37.8);
        }
        $near = $make('ZZ Nearby Hall', 55.752, 37.62);
        $make('Unknown location', null, null);
        $make('Hidden nearby', 55.752, 37.62, false);
        $filters = ['latitude' => 55.751244, 'longitude' => 37.618423, 'radius_km' => 3];

        $this->assertSame([$near->id], collect(app(ListVenuesHandler::class)
            ->handle(null, filters: $filters))->pluck('id')->values()->all());
        $this->getJson(route('venues', ['latitude' => 55.75]))
            ->assertUnprocessable()->assertJsonValidationErrors(['longitude', 'radius_km']);
        $this->getJson(route('venues', array_replace($filters, ['radius_km' => 11])))
            ->assertUnprocessable()->assertJsonValidationErrors('radius_km');
    }
}
