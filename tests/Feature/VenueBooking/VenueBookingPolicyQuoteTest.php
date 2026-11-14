<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\UseCases\PublishVenueBookingPolicyHandler;
use App\Modules\VenueBooking\Application\UseCases\ToggleVenueBookingPolicyHandler;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RequestVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Modules\VenueBooking\Domain\Models\VenueBookingQuote;
use App\Modules\VenueBooking\Domain\Models\VenueScheduleSlotPrice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

final class VenueBookingPolicyQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        Carbon::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_filament_quick_toggle_publishes_versions_and_preserves_paid_policy(): void
    {
        [$owner, $venue] = $this->ownedVenue(2);
        $toggle = app(ToggleVenueBookingPolicyHandler::class);

        try {
            $toggle->handle($venue, $owner, true);
            $this->fail('Нельзя включать аренду без опубликованных условий.');
        } catch (VenueBookingPolicyException $exception) {
            $this->assertStringContainsString('сначала опубликуйте', $exception->getMessage());
        }

        $first = app(PublishVenueBookingPolicyHandler::class)->handle(
            $venue, $owner, $this->policyData(),
        );
        $this->assertTrue($first->is_enabled);

        $disabled = $toggle->handle($venue, $owner, false);
        $this->assertFalse($disabled->is_enabled);
        $this->assertSame(2, $disabled->version);
        $this->assertNull($first->fresh()->active_marker);
        $this->assertTrue($disabled->active_marker);
        $this->assertTrue($disabled->requires_payment);
        $this->assertSame($first->whole_price_per_step_minor, $disabled->whole_price_per_step_minor);
        $this->assertSame($first->time_step_minutes, $disabled->time_step_minutes);

        $enabled = $toggle->handle($venue, $owner, true);
        $this->assertTrue($enabled->is_enabled);
        $this->assertSame(3, $enabled->version);
        $this->assertNull($disabled->fresh()->active_marker);
        $this->assertSame($enabled->id, $toggle->handle($venue, $owner, true)->id);
        $this->assertSame(3, VenueBookingPolicy::query()->where('venue_id', $venue->id)->count());
    }

    public function test_policy_versions_are_append_only_and_old_quote_keeps_original_snapshot(): void
    {
        [$owner, $venue] = $this->ownedVenue(2);
        $publish = app(PublishVenueBookingPolicyHandler::class);
        $firstPolicy = $publish->handle($venue, $owner, $this->policyData([
            'whole_price_per_step_minor' => 333,
            'half_price_per_step_minor' => 200,
        ]));
        $startsAt = CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow');

        $firstQuote = app(QuoteVenueBookingHandler::class)->handle(
            $venue,
            $startsAt,
            90,
            VenueBookingScopeEnum::WHOLE,
            $owner,
        );
        $secondPolicy = $publish->handle($venue, $owner, $this->policyData([
            'whole_price_per_step_minor' => 500,
            'half_price_per_step_minor' => 300,
        ]));
        $secondQuote = app(QuoteVenueBookingHandler::class)->handle(
            $venue,
            $startsAt,
            90,
            VenueBookingScopeEnum::WHOLE,
            $owner,
        );

        $this->assertSame(1, $firstPolicy->version);
        $this->assertSame(2, $secondPolicy->version);
        $this->assertNull($firstPolicy->refresh()->active_marker);
        $this->assertSame(999, $firstQuote->amountMinor);
        $this->assertSame(1500, $secondQuote->amountMinor);

        $storedFirstQuote = VenueBookingQuote::query()->where('public_id', $firstQuote->publicId)->firstOrFail();
        $this->assertSame($firstPolicy->id, $storedFirstQuote->policy_version_id);
        $this->assertSame(333, $storedFirstQuote->snapshot['pricing']['price_per_step_minor']);
        $this->assertSame('steps * price_per_step_minor', $storedFirstQuote->snapshot['pricing']['formula']);

        $this->expectException(LogicException::class);
        $storedFirstQuote->update(['amount_minor' => 1]);
    }

    public function test_quote_validates_local_time_step_duration_lead_time_and_horizon(): void
    {
        [$owner, $venue] = $this->ownedVenue(2);
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, $this->policyData());
        $quotes = app(QuoteVenueBookingHandler::class);

        $valid = $quotes->handle(
            $venue,
            CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow'),
            60,
            VenueBookingScopeEnum::WHOLE,
        );
        $this->assertSame('2026-08-26 09:00:00', $valid->startsAt->format('Y-m-d H:i:s'));
        $stored = VenueBookingQuote::query()->where('public_id', $valid->publicId)->firstOrFail();
        $this->assertSame('2026-08-26T09:00:00+00:00', $stored->starts_at->utc()->toIso8601String());
        $this->assertSame('2026-08-25T07:15:00+00:00', $stored->valid_until->utc()->toIso8601String());

        foreach ([
            ['2026-08-25 11:00:00', 60],
            ['2026-08-26 12:10:00', 60],
            ['2026-08-26 12:00:00', 45],
            ['2027-01-01 12:00:00', 60],
        ] as [$start, $duration]) {
            try {
                $quotes->handle($venue, CarbonImmutable::parse($start, 'Europe/Moscow'), $duration, VenueBookingScopeEnum::WHOLE);
                $this->fail("Quote must reject {$start}/{$duration}.");
            } catch (VenueBookingPolicyException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_quote_sums_time_specific_prices_and_falls_back_to_policy_price(): void
    {
        [$owner, $venue] = $this->ownedVenue(2);
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, $this->policyData([
            'whole_price_per_step_minor' => 500,
        ]));
        VenueScheduleSlotPrice::query()->create([
            'venue_id' => $venue->id,
            'day_of_week' => 3,
            'starts_at' => '12:00',
            'whole_price_per_step_minor' => 900,
        ]);

        $quote = app(QuoteVenueBookingHandler::class)->handle(
            $venue,
            CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow'),
            60,
            VenueBookingScopeEnum::WHOLE,
        );
        $stored = VenueBookingQuote::query()->where('public_id', $quote->publicId)->firstOrFail();

        $this->assertSame(1400, $quote->amountMinor);
        $this->assertSame([900, 500], $stored->snapshot['pricing']['prices_per_step_minor']);
        $this->assertTrue($stored->snapshot['pricing']['uses_custom_prices']);
        $this->assertSame('sum(prices_per_step_minor)', $stored->snapshot['pricing']['formula']);
    }

    public function test_inline_booking_status_endpoint_is_limited_to_five_refreshes_per_minute(): void
    {
        [$owner, $venue] = $this->ownedVenue(2);
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, $this->policyData());
        $quote = app(QuoteVenueBookingHandler::class)->handle(
            $venue,
            CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow'),
            60,
            VenueBookingScopeEnum::WHOLE,
            $owner,
        );
        $actor = app(CurrentActorResolver::class)->resolve($owner, null);
        $booking = app(RequestVenueBookingHandler::class)->handle($actor, $quote->publicId);
        $venue->update(['alias' => 'requested-booking-venue']);

        $this->actingAs($owner)
            ->get(route('venues.show', $venue->alias))
            ->assertOk()
            ->assertSee('data-booking-id="'.$booking->public_id.'"', false);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($owner)
                ->getJson(route('account.venue-bookings.status', $booking))
                ->assertOk()
                ->assertJsonPath('booking_id', $booking->public_id);
        }

        $this->actingAs($owner)
            ->getJson(route('account.venue-bookings.status', $booking))
            ->assertTooManyRequests();
    }

    public function test_half_quote_uses_independent_court_permission_and_physical_capability(): void
    {
        [$owner, $oneHoopVenue] = $this->ownedVenue(1);
        $publish = app(PublishVenueBookingPolicyHandler::class);
        $publish->handle($oneHoopVenue, $owner, $this->policyData(['allows_halves' => true]));

        $this->expectException(VenueBookingPolicyException::class);
        app(QuoteVenueBookingHandler::class)->handle(
            $oneHoopVenue,
            CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow'),
            60,
            VenueBookingScopeEnum::HALF_A,
        );
    }

    public function test_court_can_allow_half_rental_when_parent_default_is_disabled(): void
    {
        [$owner, $venue] = $this->ownedVenue(2);
        $court = $venue->primaryCourt()->firstOrFail();
        $court->update([
            'hoops_count' => 2,
            'supports_halves' => true,
            'allows_halves' => true,
        ]);
        app(PublishVenueBookingPolicyHandler::class)->handle(
            $venue,
            $owner,
            $this->policyData(['allows_halves' => false]),
        );

        $quote = app(QuoteVenueBookingHandler::class)->handle(
            $venue,
            CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow'),
            60,
            VenueBookingScopeEnum::HALF_A,
        );

        $this->assertSame(600, $quote->amountMinor);
    }

    public function test_stale_quote_cannot_create_booking_after_court_scope_is_disabled(): void
    {
        [$owner, $venue] = $this->ownedVenue(2);
        $court = $venue->primaryCourt()->firstOrFail();
        $court->update([
            'hoops_count' => 2,
            'supports_halves' => true,
            'allows_halves' => true,
        ]);
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, $this->policyData());
        $quote = app(QuoteVenueBookingHandler::class)->handle(
            $venue,
            CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow'),
            60,
            VenueBookingScopeEnum::HALF_A,
            $owner,
            $court,
        );
        $court->update(['allows_halves' => false]);

        $this->expectException(VenueBookingTransitionException::class);
        app(RequestVenueBookingHandler::class)->handle(
            app(CurrentActorResolver::class)->resolve($owner, null),
            $quote->publicId,
        );
    }

    public function test_user_without_commercial_permission_cannot_publish_policy(): void
    {
        [, $venue] = $this->ownedVenue(2);
        $stranger = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->expectException(VenueBookingPolicyException::class);
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $stranger, $this->policyData());
    }

    public function test_owner_enters_policy_prices_in_rubles_and_they_are_stored_in_minor_units(): void
    {
        [$owner, $venue] = $this->ownedVenue(2);

        $this->actingAs($owner)
            ->put(route('account.venues.booking-policy.update', $venue), $this->policyFormData([
                'whole_price_per_step' => '1500,50',
                'half_price_per_step' => '750',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('venue_booking_policies', [
            'venue_id' => $venue->id,
            'whole_price_per_step_minor' => 150050,
            'half_price_per_step_minor' => 75000,
        ]);
    }

    public function test_free_half_rental_accepts_an_empty_half_price(): void
    {
        [$owner, $venue] = $this->ownedVenue(2);

        $this->actingAs($owner)
            ->put(route('account.venues.booking-policy.update', $venue), $this->policyFormData([
                'requires_payment' => '0',
                'whole_price_per_step' => '0',
                'half_price_per_step' => '',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $this->assertDatabaseHas('venue_booking_policies', [
            'venue_id' => $venue->id,
            'whole_price_per_step_minor' => 0,
            'half_price_per_step_minor' => null,
        ]);
    }

    public function test_half_rental_toggle_is_disabled_without_two_playing_zones(): void
    {
        [$owner, $venue] = $this->ownedVenue(1);

        $response = $this->actingAs($owner)
            ->get(route('account.venues.booking-policy.edit', $venue))
            ->assertOk()
            ->assertSeeText('Начальное значение недоступно: в характеристиках площадки указано меньше двух игровых зон. Зал с двумя кольцами можно настроить отдельно.');

        $this->assertMatchesRegularExpression(
            '/<input[^>]+name="allows_halves"[^>]+disabled[^>]*>/s',
            $response->getContent(),
        );
    }

    public function test_http_quote_ignores_client_price_and_returns_server_snapshot(): void
    {
        [$owner, $venue] = $this->ownedVenue(2);
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, $this->policyData([
            'whole_price_per_step_minor' => 750,
        ]));

        $this->postJson(route('venues.rental.quote', $venue), [
            'starts_at' => '2026-08-26T12:00:00+03:00',
            'duration_minutes' => 60,
            'scope' => VenueBookingScopeEnum::WHOLE->value,
            'amount_minor' => 1,
            'policy_version_id' => 999999,
        ])->assertOk()
            ->assertJsonPath('amount_minor', 1500)
            ->assertJsonPath('policy_version', 1)
            ->assertJsonStructure(['quote_id', 'valid_until']);
    }

    public function test_rental_is_unavailable_without_an_active_policy(): void
    {
        [, $venue] = $this->ownedVenue(2);

        $this->get(route('venues.rental.show', $venue))->assertNotFound();
        $this->postJson(route('venues.rental.quote', $venue), [
            'starts_at' => '2026-08-26T12:00:00+03:00',
            'duration_minutes' => 60,
            'scope' => VenueBookingScopeEnum::WHOLE->value,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'QUOTE_UNAVAILABLE');
    }

    public function test_rental_routes_are_hidden_while_feature_is_disabled(): void
    {
        [, $venue] = $this->ownedVenue(2);
        config()->set('features.venue_rental.rental_flow', false);

        $this->get(route('venues.rental.show', $venue))->assertNotFound();
        $this->postJson(route('venues.rental.quote', $venue), [
            'starts_at' => '2026-08-26T12:00:00+03:00',
            'duration_minutes' => 60,
            'scope' => VenueBookingScopeEnum::WHOLE->value,
        ])->assertNotFound()
            ->assertJsonPath('code', 'feature_disabled');
    }

    public function test_date_prices_override_weekday_and_preserve_previous_quote_snapshot(): void
    {
        [$owner, $venue] = $this->ownedVenue(2);
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, $this->policyData());
        $date = CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow');
        VenueScheduleSlotPrice::query()->create([
            'venue_id' => $venue->id, 'day_of_week' => 3, 'starts_at' => '12:00',
            'whole_price_per_step_minor' => 700,
        ]);
        $venue->calendarSlotPrices()->create([
            'date' => '2026-08-26', 'starts_at' => '12:00',
            'whole_price_per_step_minor' => 800,
            'half_price_per_step_minor' => null,
        ]);

        $first = app(QuoteVenueBookingHandler::class)->handle(
            $venue, $date, 60, VenueBookingScopeEnum::WHOLE, $owner
        );
        $this->assertSame(1300, $first->amountMinor); // calendar 800 + inherited 500
        $firstStored = VenueBookingQuote::query()->where('public_id', $first->publicId)->firstOrFail();
        $this->assertSame([800, 500], $firstStored->snapshot['pricing']['prices_per_step_minor']);

        $venue->calendarSlotPrices()->whereDate('date', '2026-08-26')->update([
            'whole_price_per_step_minor' => 2000,
        ]);
        $second = app(QuoteVenueBookingHandler::class)->handle(
            $venue, $date, 60, VenueBookingScopeEnum::WHOLE, $owner
        );
        $this->assertSame(2500, $second->amountMinor);
        $this->assertSame(1300, (int) $firstStored->refresh()->amount_minor);
        $this->assertSame([800, 500], $firstStored->snapshot['pricing']['prices_per_step_minor']);
    }

    public function test_court_step_sums_venue_substeps_and_explicit_court_calendar_price_takes_priority(): void
    {
        [$owner, $venue] = $this->ownedVenue(2);
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, $this->policyData([
            'maximum_duration_minutes' => 240,
        ]));
        $court = $venue->primaryCourt()->firstOrFail();
        $court->update([
            'time_step_minutes' => 60,
            'minimum_duration_minutes' => 120,
            'maximum_duration_minutes' => 240,
        ]);
        $venue->calendarSlotPrices()->create([
            'date' => '2026-08-26', 'starts_at' => '12:00',
            'whole_price_per_step_minor' => 700,
        ]);
        $venue->calendarSlotPrices()->create([
            'date' => '2026-08-26', 'starts_at' => '12:30',
            'whole_price_per_step_minor' => 800,
        ]);

        $date = CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow');
        try {
            app(QuoteVenueBookingHandler::class)->handle($venue, $date, 60, VenueBookingScopeEnum::WHOLE, $owner, $court);
            $this->fail('Court minimum duration should reject one hour.');
        } catch (VenueBookingPolicyException) {
            $this->assertTrue(true);
        }

        $inherited = app(QuoteVenueBookingHandler::class)->handle(
            $venue, $date, 120, VenueBookingScopeEnum::WHOLE, $owner, $court
        );
        $this->assertSame(2500, $inherited->amountMinor); // 700 + 800 + 500 + 500

        $court->calendarSlotPrices()->create([
            'date' => '2026-08-26', 'starts_at' => '12:00',
            'whole_price_per_step_minor' => 1800,
        ]);
        $court->update(['whole_price_per_step_minor' => 2200]);
        $override = app(QuoteVenueBookingHandler::class)->handle(
            $venue, $date, 120, VenueBookingScopeEnum::WHOLE, $owner, $court->fresh()
        );
        $this->assertSame(4000, $override->amountMinor);
        $snapshot = VenueBookingQuote::query()->where('public_id', $override->publicId)->firstOrFail()->snapshot;
        $this->assertSame(60, $snapshot['policy']['time_step_minutes']);
        $this->assertSame(30, $snapshot['policy']['venue_time_step_minutes']);
        $this->assertSame(120, $snapshot['policy']['minimum_duration_minutes']);
        $this->assertSame([1800, 2200], $snapshot['pricing']['prices_per_step_minor']);
    }

    public function test_court_and_venue_calendar_prices_can_be_managed_without_leaking_permissions(): void
    {
        [$owner, $venue] = $this->ownedVenue(2);
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, $this->policyData());
        $court = $venue->primaryCourt()->firstOrFail();
        $params = [
            'date' => '2026-08-26',
            'starts_at' => '12:00',
            'whole_price_per_step' => '1200,50',
            'half_price_per_step' => '',
        ];

        $this->actingAs($owner)->post(route('account.venues.calendar-prices.store', $venue), $params)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(
            route('account.venues.courts.calendar-prices.store', [$venue->routeIdentifier(), $court->routeIdentifier()]),
            $params
        )->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('venue_calendar_slot_prices', [
            'venue_id' => $venue->id, 'whole_price_per_step_minor' => 120050,
        ]);
        $this->assertDatabaseHas('venue_court_calendar_slot_prices', [
            'venue_court_id' => $court->id, 'whole_price_per_step_minor' => 120050,
        ]);

        // Explicit paid slot tariffs cannot undercut the venue minimum.
        $belowMinimum = [
            'date' => '2026-08-26', 'starts_at' => '12:00',
            'whole_price_per_step' => '1', 'half_price_per_step' => '',
        ];
        $this->actingAs($owner)->post(
            route('account.venues.courts.calendar-prices.store', [$venue->routeIdentifier(), $court->routeIdentifier()]),
            $belowMinimum
        )->assertRedirect()->assertSessionHasErrors('whole_price_per_step');
        $this->assertDatabaseHas('venue_court_calendar_slot_prices', [
            'venue_court_id' => $court->id, 'whole_price_per_step_minor' => 120050,
        ]);


        $intruder = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($intruder)
            ->post(route('account.venues.calendar-prices.store', $venue), $params)->assertForbidden();
        $this->actingAs($intruder)
            ->post(route('account.venues.courts.calendar-prices.store', [$venue->routeIdentifier(), $court->routeIdentifier()]), $params)
            ->assertForbidden();
    }

    public function test_incompatible_new_venue_step_cannot_publish_over_custom_court_step(): void
    {
        [$owner, $venue] = $this->ownedVenue(2);
        $publish = app(PublishVenueBookingPolicyHandler::class);
        $publish->handle($venue, $owner, $this->policyData());
        $court = $venue->primaryCourt()->firstOrFail();
        $court->update(['time_step_minutes' => 90, 'minimum_duration_minutes' => 90]);

        $this->expectException(VenueBookingPolicyException::class);
        // Venue min 60 is divisible by 20; only the court's 90-minute step conflicts.
        $publish->handle($venue, $owner, $this->policyData(['time_step_minutes' => 20]));
    }

    public function test_active_mskba_app_theme_has_working_venue_and_court_rental_editors(): void
    {
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(\App\Presentation\Theming\ThemeResolver::class);
        \Illuminate\Support\Facades\View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));

        [$owner, $venue] = $this->ownedVenue(2);
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, $this->policyData());

        $this->actingAs($owner)->get(route('account.venues.booking-policy.edit', $venue->id))
            ->assertOk()->assertSeeText('Базовые условия площадки')->assertSeeText('Цены по датам');

        $this->actingAs($owner)->get(route('account.venues.courts.index', $venue->routeIdentifier()))
            ->assertOk()->assertSeeText('Условия аренды')->assertSeeText('Цены по датам');

        $this->get(route('venues.rental.show', $venue->id))
            ->assertOk()->assertSeeText('Аренда зала')->assertSeeText('Параметры аренды');
    }

    public function test_venue_minimum_is_enforced_for_weekly_calendar_and_court_tariffs(): void
    {
        [$owner, $venue] = $this->ownedVenue(2);
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, $this->policyData([
            'whole_price_per_step_minor' => 600,
        ]));
        $start = CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow');
        $venue->calendarSlotPrices()->create([
            'date' => '2026-08-26', 'starts_at' => '12:00',
            'whole_price_per_step_minor' => 200,
        ]);
        VenueScheduleSlotPrice::query()->create([
            'venue_id' => $venue->id, 'day_of_week' => 3, 'starts_at' => '12:30',
            'whole_price_per_step_minor' => 900,
        ]);

        $first = app(QuoteVenueBookingHandler::class)->handle(
            $venue, $start, 60, VenueBookingScopeEnum::WHOLE, $owner,
        );
        $firstStored = VenueBookingQuote::query()->where('public_id', $first->publicId)->firstOrFail();
        $this->assertSame(1500, $first->amountMinor);
        $this->assertSame([600, 900], $firstStored->snapshot['pricing']['prices_per_step_minor']);

        $court = $venue->primaryCourt()->firstOrFail();
        $court->update(['whole_price_per_step_minor' => 400]);
        $court->calendarSlotPrices()->create([
            'date' => '2026-08-26', 'starts_at' => '12:00',
            'whole_price_per_step_minor' => 1100,
        ]);
        $second = app(QuoteVenueBookingHandler::class)->handle(
            $venue, $start, 60, VenueBookingScopeEnum::WHOLE, $owner, $court->fresh(),
        );
        $secondStored = VenueBookingQuote::query()->where('public_id', $second->publicId)->firstOrFail();
        $this->assertSame(1700, $second->amountMinor);
        $this->assertSame([1100, 600], $secondStored->snapshot['pricing']['prices_per_step_minor']);

        // Prices already quoted remain immutable after changing the tariff.
        $this->assertSame([600, 900], $firstStored->fresh()->snapshot['pricing']['prices_per_step_minor']);
    }

    public function test_minimum_price_scales_with_court_step(): void
    {
        [$owner, $venue] = $this->ownedVenue(2);
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, $this->policyData([
            'whole_price_per_step_minor' => 600,
            'maximum_duration_minutes' => 240,
        ]));
        $court = $venue->primaryCourt()->firstOrFail();
        $court->update([
            'time_step_minutes' => 60,
            'minimum_duration_minutes' => 120,
            'maximum_duration_minutes' => 240,
            'whole_price_per_step_minor' => 700,
        ]);
        $start = CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow');
        $quote = app(QuoteVenueBookingHandler::class)->handle(
            $venue, $start, 120, VenueBookingScopeEnum::WHOLE, $owner, $court->fresh(),
        );
        $this->assertSame(2400, $quote->amountMinor);
        $snapshot = VenueBookingQuote::query()->where('public_id', $quote->publicId)->firstOrFail()->snapshot;
        $this->assertSame([1200, 1200], $snapshot['pricing']['prices_per_step_minor']);
    }

    /** @return array{User, Venue} */
    private function ownedVenue(int $hoopsCount): array
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE,
        ]);
        $venue->characteristics()->create(['hoops_count' => $hoopsCount]);
        $venue->schedule()->create(['timezone' => 'Europe/Moscow']);
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'status' => ContractStatusEnum::ACTIVE,
            'starts_at' => now()->subMinute(),
            'assigner' => UserParticipationRoleAssignerEnum::OTHER,
        ]);
        $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::VENUE,
            'scope_id' => $venue->id,
            'user_id' => $owner->id,
            'access_level' => VenueMembershipAccessLevelEnum::OWNER->value,
        ]);
        $contract->permissions()->createMany(array_map(
            static fn (VenuePermissionEnum $permission): array => ['permission' => $permission->value],
            VenueMembershipAccessLevelEnum::OWNER->defaultPermissions(),
        ));

        return [$owner, $venue];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function policyData(array $overrides = []): array
    {
        return [
            'is_enabled' => true,
            'allows_whole' => true,
            'allows_halves' => true,
            'minimum_duration_minutes' => 60,
            'maximum_duration_minutes' => 180,
            'time_step_minutes' => 30,
            'minimum_lead_time_minutes' => 120,
            'maximum_advance_days' => 90,
            'currency' => 'RUB',
            'whole_price_per_step_minor' => 500,
            'half_price_per_step_minor' => 300,
            'hold_duration_minutes' => 30,
            'requires_payment' => true,
            'payment_window_minutes' => 30,
            'quote_validity_minutes' => 15,
            'cancellation_before_minutes' => 1440,
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function policyFormData(array $overrides = []): array
    {
        return [
            'is_enabled' => '1',
            'allows_whole' => '1',
            'allows_halves' => '1',
            'minimum_duration_minutes' => '60',
            'maximum_duration_minutes' => '180',
            'time_step_minutes' => '30',
            'minimum_lead_time_minutes' => '120',
            'maximum_advance_days' => '90',
            'currency' => 'RUB',
            'whole_price_per_step' => '500',
            'half_price_per_step' => '300',
            'hold_duration_minutes' => '30',
            'allows_hold_extension' => '0',
            'maximum_hold_extension_minutes' => '30',
            'requires_payment' => '1',
            'payment_window_minutes' => '30',
            'quote_validity_minutes' => '15',
            'cancellation_before_minutes' => '1440',
            ...$overrides,
        ];
    }
}
