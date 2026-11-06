<?php

namespace Tests\Feature\Venue;

use App\Modules\Venue\Application\UseCases\ListVenuesHandler;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueCatalogCourtAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_filter_matches_the_free_court_and_links_to_its_public_page(): void
    {
        $venue = Venue::factory()->create([
            'name' => 'Школа №1794',
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => true,
            'requires_booking' => true,
            'requires_booking_approval' => true,
        ]);
        $outdoor = $venue->primaryCourt()->firstOrFail();
        $outdoor->update([
            'name' => 'Уличная площадка',
            'requires_payment' => false,
            'requires_booking' => false,
            'requires_booking_approval' => false,
        ]);
        $indoor = $venue->courts()->create([
            'name' => 'Школьный зал',
            'alias' => 'school-hall',
            'sort_order' => 20,
            'allows_whole' => true,
            'requires_payment' => true,
            'requires_booking' => true,
            'requires_booking_approval' => true,
        ]);

        $handler = app(ListVenuesHandler::class);
        $free = collect($handler->handle(null, null, ['access' => 'free']))->firstWhere('id', $venue->id);
        $this->assertNotNull($free);
        $this->assertSame(route('venues.courts.show', [$venue->routeIdentifier(), $outdoor->routeIdentifier()]), $free->matchedCourtUrl);
        $this->assertSame('Уличная площадка', $free->matchedCourtName);
        $this->assertFalse($free->matchedRequiresBooking);
        $this->assertCount(1, $free->courtAccessSummary['free']);
        $this->assertCount(1, $free->courtAccessSummary['paid']);

        $paid = collect($handler->handle(null, null, ['access' => 'paid']))->firstWhere('id', $venue->id);
        $this->assertNotNull($paid);
        $this->assertSame(route('venues.courts.show', [$venue->routeIdentifier(), $indoor->routeIdentifier()]), $paid->matchedCourtUrl);

        $approval = collect($handler->handle(null, null, ['access' => 'approval']))->firstWhere('id', $venue->id);
        $this->assertNotNull($approval);
        $this->assertSame($indoor->id, $approval->courtAccessSummary['approval'][0]['court_id']);

        $this->get(route('venues', ['access' => 'free']))
            ->assertOk()
            ->assertSee('Подходящий зал: Уличная площадка')
            ->assertSee($free->matchedCourtUrl, false);

        $this->get(route('venues', ['access' => 'free', 'view' => 'map']))
            ->assertOk()
            ->assertSee(str_replace('/', '\\/', $free->matchedCourtUrl), false);
    }

    public function test_inherited_conditions_closed_courts_and_unknown_payment_do_not_match_free(): void
    {
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => true,
            'requires_booking' => true,
        ]);
        $inherited = $venue->primaryCourt()->firstOrFail();
        $this->assertNull(collect(app(ListVenuesHandler::class)->handle(null, null, ['access' => 'free']))->firstWhere('id', $venue->id));

        $inherited->update(['requires_payment' => false, 'operational_status' => VenueOperationalStatusEnum::TEMPORARILY_CLOSED]);
        $this->assertNull(collect(app(ListVenuesHandler::class)->handle(null, null, ['access' => 'free']))->firstWhere('id', $venue->id));

        $inherited->update(['operational_status' => null]);
        $this->assertNotNull(collect(app(ListVenuesHandler::class)->handle(null, null, ['access' => 'free']))->firstWhere('id', $venue->id));

        $inherited->update(['requires_payment' => null]);
        $venue->update(['requires_payment' => null]);
        $unknown = collect(app(ListVenuesHandler::class)->handle(null, null))->firstWhere('id', $venue->id);
        $this->assertCount(1, $unknown->courtAccessSummary['unknown']);
        $this->assertNull(collect(app(ListVenuesHandler::class)->handle(null, null, ['access' => 'free']))->firstWhere('id', $venue->id));
    }
}
