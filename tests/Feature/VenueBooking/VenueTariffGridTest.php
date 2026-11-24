<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\VenueSlotPricing;
use App\Modules\VenueBooking\Application\Services\VenueTariffGrid;
use App\Modules\VenueBooking\Application\UseCases\PublishVenueBookingPolicyHandler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use App\Presentation\Theming\ThemeResolver;
use Tests\TestCase;

final class VenueTariffGridTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
    }

    public function test_app_schedule_embeds_venue_grid_before_collapsible_court_grids_and_returns_to_schedule(): void
    {
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));

        [$owner, $venue] = $this->ownedVenue();
        $this->policy($venue, $owner);
        $court = $venue->primaryCourt()->firstOrFail();
        $this->actingAs($owner);

        $url = route('account.venues.edit', ['alias' => $venue->routeIdentifier(), 'tab' => 'schedule']);
        $response = $this->get($url)->assertOk()
            ->assertSee('Тарифная сетка площадки')
            ->assertSee('Тарифы залов и кортов')
            ->assertSee('venue-tariff-panel-venue', false)
            ->assertSee('venue-tariff-panel-court-'.$court->id, false)
            ->assertSee('data-venue-court-tariffs', false)
            ->assertSee('return_to_schedule', false);
        $body = $response->getContent();
        $this->assertLessThan(
            strpos($body, 'Тарифы залов и кортов'),
            strpos($body, 'Тарифная сетка площадки'),
        );

        $this->post(route('account.venues.tariff-grid.store', $venue->id), [
            'court_id' => $court->id,
            'return_to_schedule' => 1,
            'mode' => 'weekly',
            'ranges' => [
                [
                    'days' => [1], 'starts_at' => '07:00', 'ends_at' => '08:00',
                    'operation' => 'set', 'whole_price_per_step' => '1000',
                ],
            ],
        ])->assertRedirect(
            route('account.venues.edit', [
                'alias' => $venue->routeIdentifier(),
                'tab' => 'schedule',
                'court' => $court->id,
                'mode' => 'weekly',
            ]).'#venue-tariff-panel-court-'.$court->id,
        )->assertSessionHas('status');

        $reopened = $this->get($url.'&court='.$court->id)
            ->assertOk()->assertSee('Понедельник');
        $this->assertMatchesRegularExpression(
            '/id="venue-tariff-panel-court-'.$court->id.'"\\s+open\\b/',
            $reopened->getContent(),
        );
    }

    public function test_court_inherits_both_parent_time_ranges_and_restores_them_after_override_reset(): void
    {
        [$owner, $venue] = $this->ownedVenue();
        $policy = $this->policy($venue, $owner);
        $court = $venue->primaryCourt()->firstOrFail();
        $this->actingAs($owner);

        $this->post(route('account.venues.tariff-grid.store', $venue->id), [
            'mode' => 'weekly',
            'ranges' => [
                [
                    'days' => [1],
                    'starts_at' => '07:00', 'ends_at' => '16:00',
                    'operation' => 'set', 'whole_price_per_step' => '1000',
                ],
                [
                    'days' => [1],
                    'starts_at' => '16:00', 'ends_at' => '23:30',
                    'operation' => 'set', 'whole_price_per_step' => '1500',
                ],
            ],
        ])->assertRedirect();

        $quote = fn (): array => app(VenueSlotPricing::class)->calculate(
            $venue, $policy,
            CarbonImmutable::parse('2026-11-30 15:30:00', 'Europe/Moscow'),
            60, VenueBookingScopeEnum::WHOLE, $court,
            ['time_step_minutes' => 30, 'requires_payment' => true],
        )['prices_per_step_minor'];
        $this->assertSame([100000, 150000], $quote());

        $this->post(route('account.venues.tariff-grid.store', $venue->id), [
            'court_id' => $court->id, 'mode' => 'weekly',
            'days' => [1], 'starts_at' => '16:00', 'ends_at' => '16:30',
            'operation' => 'set', 'whole_price_per_step' => '1900',
        ])->assertRedirect();
        $this->assertSame([100000, 190000], $quote());

        $this->post(route('account.venues.tariff-grid.store', $venue->id), [
            'court_id' => $court->id, 'mode' => 'weekly',
            'days' => [1], 'starts_at' => '16:00', 'ends_at' => '16:30',
            'operation' => 'clear',
        ])->assertRedirect();

        $this->assertSame([100000, 150000], $quote());
        $this->assertSame(0, DB::table('venue_court_manual_slot_prices')
            ->where('venue_court_id', $court->id)->count());
    }

    public function test_two_non_overlapping_ranges_save_together_with_a_day_specific_exception(): void
    {
        [$owner, $venue] = $this->ownedVenue();
        $this->policy($venue, $owner);
        $court = $venue->primaryCourt()->firstOrFail();

        $this->actingAs($owner)->post(route('account.venues.tariff-grid.store', $venue->id), [
            'court_id' => $court->id, 'mode' => 'weekly',
            'ranges' => [
                [
                    'days' => [1, 2], 'starts_at' => '07:00', 'ends_at' => '16:00',
                    'operation' => 'set', 'whole_price_per_step' => '1000',
                ],
                [
                    'days' => [1, 2], 'starts_at' => '16:00', 'ends_at' => '23:30',
                    'operation' => 'set', 'whole_price_per_step' => '1500',
                    'exceptions' => [
                        [
                            'starts_at' => '18:00', 'day_of_week' => 2,
                            'operation' => 'set', 'whole_price_per_step' => '2200',
                        ],
                    ],
                ],
            ],
        ])->assertRedirect()->assertSessionHas('status');

        // (18 + 15) steps per day, on two days.
        $this->assertSame(66, DB::table('venue_court_manual_slot_prices')->count());
        $this->assertDatabaseHas('venue_court_manual_slot_prices', [
            'venue_court_id' => $court->id, 'day_of_week' => 1,
            'starts_at' => '15:30:00', 'whole_price_per_step_minor' => 100000,
        ]);
        $this->assertDatabaseHas('venue_court_manual_slot_prices', [
            'venue_court_id' => $court->id, 'day_of_week' => 1,
            'starts_at' => '16:00:00', 'whole_price_per_step_minor' => 150000,
        ]);
        $this->assertDatabaseHas('venue_court_manual_slot_prices', [
            'venue_court_id' => $court->id, 'day_of_week' => 2,
            'starts_at' => '18:00:00', 'whole_price_per_step_minor' => 220000,
        ]);
    }

    public function test_overlapping_bulk_ranges_are_rejected_without_partial_saves(): void
    {
        [$owner, $venue] = $this->ownedVenue();
        $this->policy($venue, $owner);
        $court = $venue->primaryCourt()->firstOrFail();

        $this->actingAs($owner)->post(route('account.venues.tariff-grid.store', $venue->id), [
            'court_id' => $court->id, 'mode' => 'weekly',
            'ranges' => [
                [
                    'days' => [1], 'starts_at' => '07:00', 'ends_at' => '16:00',
                    'operation' => 'set', 'whole_price_per_step' => '1000',
                ],
                [
                    'days' => [1], 'starts_at' => '15:30', 'ends_at' => '23:30',
                    'operation' => 'set', 'whole_price_per_step' => '1500',
                ],
            ],
        ])->assertSessionHasErrors('ranges.1.starts_at');

        $this->assertSame(0, DB::table('venue_court_manual_slot_prices')->count());
    }

    public function test_weekly_range_is_expanded_into_steps_with_an_exception(): void
    {
        [$owner, $venue] = $this->ownedVenue();
        $policy = $this->policy($venue, $owner);
        $court = $venue->primaryCourt()->firstOrFail();
        $this->actingAs($owner)->post(route('account.venues.tariff-grid.store', $venue->id), [
            'court_id' => $court->id,
            'mode' => 'weekly',
            'days' => [1, 2],
            'starts_at' => '18:00',
            'ends_at' => '20:00',
            'operation' => 'set',
            'whole_price_per_step' => '1200',
            'exceptions' => [
                ['starts_at' => '19:00', 'day_of_week' => 2, 'operation' => 'set', 'whole_price_per_step' => '1500'],
            ],
        ])->assertRedirect();

        $this->assertSame(8, DB::table('venue_court_manual_slot_prices')->count());
        $this->assertDatabaseHas('venue_court_manual_slot_prices', [
            'venue_court_id' => $court->id, 'day_of_week' => 2,
            'starts_at' => '19:00:00', 'whole_price_per_step_minor' => 150000,
        ]);
        $grid = app(VenueTariffGrid::class)->read($venue, $policy, $court, 'weekly', '2026-11-23');
        $monday = collect($grid)->firstWhere('day', 1);
        $this->assertNotNull($monday);
        $prices = collect($monday['ranges'])->flatMap(fn (array $r) => $r['cells'])
            ->keyBy('time');
        $this->assertSame(120000, $prices['18:00']['whole']);
        $this->assertSame(120000, $prices['19:00']['whole']);
        $tuesday = collect($grid)->firstWhere('day', 2);
        $tuesdayPrices = collect($tuesday['ranges'])->flatMap(fn (array $r) => $r['cells'])->keyBy('time');
        $this->assertSame(150000, $tuesdayPrices['19:00']['whole']);
        $this->assertSame(120000, $prices['19:30']['whole']);
    }

    public function test_imported_prices_remain_immutable_after_manual_overrides_and_refresh(): void
    {
        [$owner, $venue] = $this->ownedVenue();
        $policy = $this->policy($venue, $owner);
        $court = $venue->primaryCourt()->firstOrFail();
        $source = $court->calendarSlotPrices()->create([
            'date' => '2026-11-25', 'starts_at' => '18:00',
            'whole_price_per_step_minor' => 75000,
        ]);
        $court->calendarSlotPrices()->create([
            'date' => '2026-11-25', 'starts_at' => '18:30',
            'whole_price_per_step_minor' => 75000,
        ]);

        $this->actingAs($owner)->post(route('account.venues.tariff-grid.store', $venue->id), [
            'court_id' => $court->id, 'mode' => 'date', 'date' => '2026-11-25',
            'starts_at' => '18:00', 'ends_at' => '18:30',
            'operation' => 'set', 'whole_price_per_step' => '900',
        ])->assertRedirect();

        $this->assertSame(75000, $source->fresh()->whole_price_per_step_minor);
        $quote = fn () => app(VenueSlotPricing::class)->calculate(
            $venue, $policy,
            CarbonImmutable::parse('2026-11-25 18:00:00', 'Europe/Moscow'),
            60, VenueBookingScopeEnum::WHOLE, $court,
            ['time_step_minutes' => 30, 'requires_payment' => true],
        );
        $this->assertSame([90000, 75000], $quote()['prices_per_step_minor']);

        // External import replaces its own slot, but not the manual overlay.
        $source->update(['whole_price_per_step_minor' => 80000]);
        $this->assertSame([90000, 75000], $quote()['prices_per_step_minor']);

        $this->actingAs($owner)->post(route('account.venues.tariff-grid.store', $venue->id), [
            'court_id' => $court->id, 'mode' => 'date', 'date' => '2026-11-25',
            'starts_at' => '18:00', 'ends_at' => '18:30', 'operation' => 'clear',
        ])->assertRedirect();
        $this->assertSame([80000, 75000], $quote()['prices_per_step_minor']);
        $this->assertSame(0, DB::table('venue_court_manual_slot_prices')->count());
    }

    public function test_price_grid_uses_existing_venue_calendar_tables_without_an_integration(): void
    {
        [$owner, $venue] = $this->ownedVenue();
        $this->policy($venue, $owner);
        $this->actingAs($owner)->post(route('account.venues.tariff-grid.store', $venue->id), [
            'mode' => 'date', 'date' => '2026-11-25',
            'starts_at' => '18:00', 'ends_at' => '19:00',
            'operation' => 'set', 'whole_price_per_step' => '1400',
        ])->assertRedirect();
        $this->assertSame(2, $venue->calendarSlotPrices()->count());
        $this->assertSame(0, DB::table('venue_court_manual_slot_prices')->count());
    }

    public function test_misaligned_slots_under_minimum_prices_and_unauthorized_writes_are_rejected(): void
    {
        [$owner, $venue] = $this->ownedVenue();
        $this->policy($venue, $owner);
        $court = $venue->primaryCourt()->firstOrFail();
        $url = route('account.venues.tariff-grid.store', $venue->id);
        $payload = [
            'court_id' => $court->id,
            'mode' => 'weekly', 'days' => [1],
            'starts_at' => '18:10', 'ends_at' => '19:00',
            'operation' => 'set', 'whole_price_per_step' => '900',
        ];
        $this->actingAs($owner)->post($url, $payload)
            ->assertSessionHasErrors('starts_at');
        $this->actingAs($owner)->post($url, array_replace($payload, [
            'starts_at' => '18:00', 'whole_price_per_step' => '100',
        ]))->assertSessionHasErrors('whole_price_per_step');
        $this->actingAs(User::factory()->create(['status' => UserStatusEnum::CONFIRMED]))
            ->post($url, array_replace($payload, ['starts_at' => '18:00']))
            ->assertForbidden();
        $this->assertSame(0, DB::table('venue_court_manual_slot_prices')->count());
    }

    /** @return array{User, Venue} */
    private function ownedVenue(): array
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'name' => 'Test owner', 'status' => ContractStatusEnum::ACTIVE,
            'assigner' => UserParticipationRoleAssignerEnum::OTHER->value,
            'starts_at' => now()->subDay(),
        ]);
        $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::VENUE,
            'scope_id' => $venue->id,
            'user_id' => $owner->id,
            'access_level' => VenueMembershipAccessLevelEnum::OWNER->value,
        ]);
        $contract->permissions()->createMany(array_map(
            fn ($p): array => ['permission' => $p->value],
            VenueMembershipAccessLevelEnum::OWNER->defaultPermissions(),
        ));

        return [$owner, $venue];
    }

    private function policy(Venue $venue, User $owner): \App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy
    {
        return app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, [
            'is_enabled' => true, 'allows_whole' => true, 'allows_halves' => false,
            'minimum_duration_minutes' => 60, 'maximum_duration_minutes' => 180,
            'time_step_minutes' => 30, 'minimum_lead_time_minutes' => 120,
            'maximum_advance_days' => 90, 'currency' => 'RUB',
            'whole_price_per_step_minor' => 50000, 'half_price_per_step_minor' => null,
            'hold_duration_minutes' => 30, 'requires_payment' => true,
            'payment_window_minutes' => 30, 'quote_validity_minutes' => 15,
            'cancellation_before_minutes' => 1440,
        ]);
    }
}
