<?php

namespace Tests\Feature\Venue;

use App\Modules\Venue\Application\UseCases\ListVenuesHandler;
use App\Modules\Venue\Application\UseCases\SearchVenuesHandler;
use App\Modules\Venue\Domain\Enums\VenueCourtEnvironmentEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
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
        $this->assertSame(['free'], $free->paymentBadgeStates());
        $unfiltered = collect($handler->handle(null))->firstWhere('id', $venue->id);
        $this->assertSame(['free', 'paid'], $unfiltered->paymentBadgeStates());
        $this->assertSame(['free'], $unfiltered->paymentBadgeStates($free->matchedCourtUrl));

        $paid = collect($handler->handle(null, null, ['access' => 'paid']))->firstWhere('id', $venue->id);
        $this->assertNotNull($paid);
        $this->assertSame(route('venues.courts.show', [$venue->routeIdentifier(), $indoor->routeIdentifier()]), $paid->matchedCourtUrl);
        $this->assertSame(['paid'], $paid->paymentBadgeStates());
        $this->assertSame(['paid'], $unfiltered->paymentBadgeStates($paid->matchedCourtUrl));

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

    public function test_playing_space_type_and_access_match_the_same_court_without_changing_venue_type(): void
    {
        $school = Venue::factory()->create([
            'name' => 'Школа №1794',
            'type' => VenueTypeEnum::SCHOOL,
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => true,
        ]);
        $school->primaryCourt()->firstOrFail()->update([
            'environment' => VenueCourtEnvironmentEnum::INDOOR,
            'requires_payment' => false,
        ]);
        $outdoor = $school->courts()->create([
            'name' => 'Уличный корт',
            'alias' => 'outdoor',
            'environment' => VenueCourtEnvironmentEnum::OUTDOOR,
            'sort_order' => 20,
            'allows_whole' => true,
            'requires_payment' => true,
        ]);

        $handler = app(ListVenuesHandler::class);
        foreach (['school', 'sports_hall', 'street_court'] as $type) {
            $item = collect($handler->handle(null, null, ['type' => $type]))->firstWhere('id', $school->id);
            $this->assertNotNull($item);
            $this->assertSame('school', $item->typeSlug);
            $this->getJson(route('api.v1.venues.index', ['type' => $type]))
                ->assertOk()->assertJsonPath('data.0.id', $school->id)
                ->assertJsonPath('data.0.type', 'school');
        }

        $filters = ['type' => 'street_court', 'access' => 'free'];
        $this->assertNull(collect($handler->handle(null, null, $filters))->firstWhere('id', $school->id));
        $this->getJson(route('api.v1.venues.index', $filters))->assertOk()->assertJsonCount(0, 'data');

        $paid = collect($handler->handle(null, null, ['type' => 'street_court', 'access' => 'paid']))->firstWhere('id', $school->id);
        $this->assertNotNull($paid);
        $this->assertSame('Уличный корт', $paid->matchedCourtName);

        $search = app(SearchVenuesHandler::class);
        $this->assertSame([$school->id], array_map(fn ($item) => $item->id,
            $search->handle(null, null, type: VenueTypeEnum::STREET_COURT, confirmedOnly: true)));
        $this->assertSame([], $search->handle(null, null, type: VenueTypeEnum::STREET_COURT,
            requiresPayment: false, confirmedOnly: true));

        // Reverse the payment conditions: a free outdoor court must be found
        // even when a paid indoor hall belongs to the same school.
        $school->primaryCourt()->firstOrFail()->update(['requires_payment' => true]);
        $outdoor->update(['requires_payment' => false]);
        $filters = ['type' => 'street_court', 'access' => 'free'];
        $free = collect($handler->handle(null, null, $filters))->firstWhere('id', $school->id);
        $this->assertNotNull($free);
        $url = route('venues.courts.show', [$school->routeIdentifier(), $outdoor->routeIdentifier()]);
        $this->assertSame($url, $free->matchedCourtUrl);
        $this->getJson(route('api.v1.venues.index', $filters))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.url', $url)
            ->assertJsonPath('data.0.matched_court.court_id', $outdoor->id);

        $this->get(route('venues', $filters))->assertOk()->assertSee($url, false);

        $outdoor->delete();
        $this->assertNull(collect($handler->handle(null, null, ['type' => 'street_court']))->firstWhere('id', $school->id));
        $this->getJson(route('api.v1.venues.index', ['type' => 'street_court']))->assertOk()->assertJsonCount(0, 'data');
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
        $this->assertSame(['unknown'], $unknown->paymentBadgeStates());
        $this->assertNull(collect(app(ListVenuesHandler::class)->handle(null, null, ['access' => 'free']))->firstWhere('id', $venue->id));
    }
}
