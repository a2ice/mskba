<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EventCatalogPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_catalog_uses_default_category_shell_filters_and_views(): void
    {
        $location = Location::factory()->create();
        $venue = Venue::factory()->create(['location_id' => $location->id]);
        Event::factory()->create([
            'venue_id' => $venue->id,
            'title' => 'Открытая игра MSKBA',
            'type' => EventTypeEnum::GAME->value,
        ]);

        $this->get(route('events.index', ['type' => EventTypeEnum::GAME->value]))
            ->assertOk()
            ->assertSee('data-default-category', false)
            ->assertSee('events-category-catalog', false)
            ->assertSee('data-default-category-sidebar-accordion', false)
            ->assertSee('event-catalog-filter-form-desktop', false)
            ->assertSee('event-catalog-filter-form-mobile', false)
            ->assertSee('data-default-category-results="cards"', false)
            ->assertSee('data-default-category-results="list"', false)
            ->assertSee('data-default-category-results="map"', false)
            ->assertSee('data-event-category-map-points', false)
            ->assertSee('Открытая игра MSKBA')
            ->assertSee('title="Создать мероприятие"', false);
    }

    public function test_event_map_data_uses_only_events_visible_in_catalog_query(): void
    {
        $location = Location::factory()->create();
        $venue = Venue::factory()->create(['location_id' => $location->id]);

        Event::factory()->create([
            'venue_id' => $venue->id,
            'title' => 'Публичное мероприятие',
            'type' => EventTypeEnum::GAME->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
        ]);
        Event::factory()->create([
            'venue_id' => $venue->id,
            'title' => 'Приватное мероприятие',
            'type' => EventTypeEnum::GAME->value,
            'visibility' => EventVisibilityEnum::PRIVATE->value,
        ]);

        $this->get(route('events.index', [
            'type' => EventTypeEnum::GAME->value,
            'view' => 'map',
        ]))
            ->assertOk()
            ->assertSee('Публичное мероприятие')
            ->assertDontSee('Приватное мероприятие')
            ->assertSee('data-default-category-view="map"', false);
    }
}
