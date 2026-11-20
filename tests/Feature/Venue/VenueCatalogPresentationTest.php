<?php

namespace Tests\Feature\Venue;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
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
}
