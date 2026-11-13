<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\Venue\Application\Services\PublicVenueDaySchedule;
use App\Modules\VenueBooking\Application\Services\VenueCommissionCalculator;
use App\Modules\VenueBooking\Application\Services\VenueCommissionManager;
use App\Modules\VenueBooking\Application\Services\VenuePricingRuleManager;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Modules\VenueBooking\Domain\Models\VenueBookingQuote;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class VenueCommissionPricingTest extends TestCase
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

    public function test_zero_by_default_does_not_change_prices_or_silently_assign_any_agent(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $calc = app(VenueCommissionCalculator::class);
        $result = $calc->calculate($venue, $court, 200000, 'RUB');
        $this->assertSame(200000, $result['total_minor']);
        $this->assertSame(0, $result['portal_fee_minor']);
        $this->assertSame(0, $result['agent_fee_minor']);
        $this->assertNull($result['agent']);
        $this->assertSame('global', $result['portal']['source']);

        $quote = $this->quote($venue, $court);
        $this->assertSame(200000, $quote->amountMinor);
        $this->assertSame(0, $quote->commissionBreakdown['portal_fee_minor']);
        $this->assertSame(0, $quote->commissionBreakdown['agent_fee_minor']);
    }

    public function test_global_ten_percent_and_one_selected_five_percent_agent_are_both_added_to_same_subtotal(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $manager = app(VenueCommissionManager::class);
        $agentOne = $manager->saveAgent($venue, $admin, $this->activeUser(), 'percent', '5');
        $agentTwo = $manager->saveAgent($venue, $admin, $this->activeUser(), 'percent', '7');
        $manager->setPortal($admin, 'percent', '10');
        $manager->setVenue($venue, $admin, 'inherit', selectedAgentId: $agentOne->id);

        $quote = $this->quote($venue, $court);
        $this->assertSame(230000, $quote->amountMinor);
        $this->assertSame(20000, $quote->commissionBreakdown['portal_fee_minor']);
        $this->assertSame(10000, $quote->commissionBreakdown['agent_fee_minor']);
        $this->assertSame($agentOne->id, $quote->commissionBreakdown['agent']['assignment_id']);
        $this->assertNotSame($agentTwo->id, $quote->commissionBreakdown['agent']['assignment_id']);
        $this->assertSame(200000, $quote->commissionBreakdown['provider_amount_minor']);

        $row = VenueBookingQuote::query()->where('public_id', $quote->publicId)->firstOrFail();
        $this->assertSame(4, $row->snapshot['schema_version']);
        $this->assertSame(230000, $row->snapshot['pricing']['amount_minor']);
        $this->assertSame(200000, $row->snapshot['pricing']['subtotal_after_rules_minor']);
        $this->assertSame(20000, $row->snapshot['pricing']['commissions']['portal_fee_minor']);
        $this->assertSame($agentOne->id, $row->snapshot['pricing']['commissions']['agent']['assignment_id']);
        $this->assertSame(1, $row->snapshot['pricing']['commissions']['portal']['version']);

        // Another eligible agent is present, but never accidentally stacked.
        $manager->setVenue($venue, $admin, 'inherit', selectedAgentId: $agentTwo->id);
        $next = $this->quote($venue, $court);
        $this->assertSame(234000, $next->amountMinor);
        $this->assertSame(14000, $next->commissionBreakdown['agent_fee_minor']);
        $this->assertSame(230000, $row->refresh()->amount_minor);
        $this->assertSame($agentOne->id, $row->snapshot['pricing']['commissions']['agent']['assignment_id']);
    }

    public function test_portal_commission_inherits_global_then_venue_then_court_with_explicit_zero(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $manager = app(VenueCommissionManager::class);
        $agent = $manager->saveAgent($venue, $admin, $this->activeUser(), 'percent', '5');
        $manager->setPortal($admin, 'percent', '10');
        $manager->setVenue($venue, $admin, 'inherit', selectedAgentId: $agent->id);
        $calc = app(VenueCommissionCalculator::class);

        $this->assertSame(230000, $calc->calculate($venue, $court, 200000, 'RUB')['total_minor']);
        $manager->setVenue($venue, $admin, 'percent', '8', $agent->id);
        $this->assertSame(226000, $calc->calculate($venue, $court, 200000, 'RUB')['total_minor']);
        $manager->setCourt($venue, $court, $admin, 'percent', '3');
        $courtResult = $calc->calculate($venue, $court, 200000, 'RUB');
        $this->assertSame(216000, $courtResult['total_minor']);
        $this->assertSame('court', $courtResult['portal']['source']);

        $manager->setCourt($venue, $court, $admin, 'none');
        $this->assertSame(210000, $calc->calculate($venue, $court, 200000, 'RUB')['total_minor']);

        $manager->setCourt($venue, $court, $admin, 'inherit');
        $manager->setVenue($venue, $admin, 'none', selectedAgentId: $agent->id);
        $this->assertSame(210000, $calc->calculate($venue, $court, 200000, 'RUB')['total_minor']);
        $manager->setVenue($venue, $admin, 'inherit', selectedAgentId: $agent->id);
        $this->assertSame(230000, $calc->calculate($venue, $court, 200000, 'RUB')['total_minor']);
    }

    public function test_disabling_both_fees_is_distinct_from_zero_portal_rate_and_court_can_override(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $manager = app(VenueCommissionManager::class);
        $manager->setPortal($admin, 'percent', '10');
        $agent = $manager->saveAgent($venue, $admin, $this->activeUser(), 'percent', '5');

        $manager->setVenue($venue, $admin, 'percent', '0', $agent->id);
        $zeroPortal = $this->quote($venue, $court);
        $this->assertSame(210000, $zeroPortal->amountMinor);
        $this->assertSame(0, $zeroPortal->commissionBreakdown['portal_fee_minor']);
        $this->assertSame(10000, $zeroPortal->commissionBreakdown['agent_fee_minor']);

        $manager->setVenue($venue, $admin, 'inherit', selectedAgentId: $agent->id, disableAllCommissions: true);
        $disabled = $this->quote($venue, $court);
        $this->assertSame(200000, $disabled->amountMinor);
        $this->assertTrue($disabled->commissionBreakdown['all_fees']['disabled']);
        $this->assertSame('venue', $disabled->commissionBreakdown['all_fees']['source']);
        $this->assertSame(0, $disabled->commissionBreakdown['agent_fee_minor']);

        $manager->setCourt($venue, $court, $admin, 'inherit', allFeesMode: 'enabled');
        $this->assertSame(230000, $this->quote($venue, $court)->amountMinor);

        $manager->setCourt($venue, $court, $admin, 'inherit', allFeesMode: 'inherit');
        $this->assertSame(200000, $this->quote($venue, $court)->amountMinor);

        $manager->setVenue($venue, $admin, 'inherit', selectedAgentId: $agent->id, disableAllCommissions: false);
        $manager->setCourt($venue, $court, $admin, 'inherit', allFeesMode: 'disabled');
        $courtDisabled = $this->quote($venue, $court);
        $this->assertSame(200000, $courtDisabled->amountMinor);
        $this->assertSame('court', $courtDisabled->commissionBreakdown['all_fees']['source']);

        $row = VenueBookingQuote::query()->where('public_id', $disabled->publicId)->firstOrFail();
        $this->assertSame(200000, $row->snapshot['pricing']['amount_minor']);
        $this->assertSame('venue', $row->snapshot['pricing']['commissions']['all_fees']['source']);
    }

    public function test_discount_is_applied_before_both_commissions_and_old_quotes_do_not_change(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $manager = app(VenueCommissionManager::class);
        $manager->setPortal($admin, 'percent', '10');
        $agent = $manager->saveAgent($venue, $admin, $this->activeUser(), 'percent', '5');
        $manager->setVenue($venue, $admin, 'inherit', selectedAgentId: $agent->id);
        $old = $this->quote($venue, $court);

        $rules = app(VenuePricingRuleManager::class);
        $rules->publish($venue, $admin, $rules->saveDraft($venue, $admin, [
            'name' => 'Скидка 30% от двух часов',
            'priority' => 100, 'is_enabled' => true, 'stop_processing' => false,
            'scope' => 'all', 'min_duration_minutes' => 120,
            'operation' => 'percent_discount', 'value_text' => '30',
        ]));

        $discounted = $this->quote($venue, $court);
        $this->assertSame(140000, $discounted->commissionBreakdown['provider_amount_minor']);
        $this->assertSame(14000, $discounted->commissionBreakdown['portal_fee_minor']);
        $this->assertSame(7000, $discounted->commissionBreakdown['agent_fee_minor']);
        $this->assertSame(161000, $discounted->amountMinor);

        $manager->setPortal($admin, 'percent', '8');
        $this->assertSame(158200, $this->quote($venue, $court)->amountMinor);

        $oldRow = VenueBookingQuote::query()->where('public_id', $old->publicId)->firstOrFail();
        $this->assertSame(230000, $oldRow->amount_minor);
        $this->assertSame(20000, $oldRow->snapshot['pricing']['commissions']['portal_fee_minor']);
        $this->assertSame(1, $oldRow->snapshot['pricing']['commissions']['portal']['version']);
    }

    public function test_scoped_zero_is_not_inheritance_and_inherit_restores_global_rate(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $manager = app(VenueCommissionManager::class);
        $manager->setPortal($admin, 'percent', '10');
        $this->assertSame(220000, $this->quote($venue, $court)->amountMinor);

        $scoped = $manager->setScoped($admin, 'venue', 'rental', 'percent', '0');
        $this->assertSame('percent', $scoped->mode);
        $this->assertSame(0, $scoped->value);
        $explicitZero = $this->quote($venue, $court);
        $this->assertSame(200000, $explicitZero->amountMinor);
        $this->assertSame('scope', $explicitZero->commissionBreakdown['portal']['source']);

        $inherited = $manager->setScoped($admin, 'venue', 'rental', 'inherit');
        $this->assertNull($inherited->mode);
        $this->assertNull($inherited->value);
        $this->assertSame(220000, $this->quote($venue, $court)->amountMinor);
    }

    public function test_public_day_prices_include_commissions_and_range_preview_charges_fixed_fee_once(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $date = CarbonImmutable::now('Europe/Moscow')->addDay()->startOfDay();
        $venue->schedule()->firstOrFail()->intervals()->create([
            'day_of_week' => $date->dayOfWeekIso,
            'starts_at' => '07:00',
            'ends_at' => '23:59',
            'sort_order' => 0,
        ]);

        $manager = app(VenueCommissionManager::class);
        $manager->setPortal($admin, 'percent', '10');
        $days = app(PublicVenueDaySchedule::class);
        $schedule = $days->forDate($venue, $court, $date->toDateString());
        $slot = collect($schedule['slots'])->firstWhere('starts_at', '12:00');
        $this->assertSame(50000, $slot['base_price_minor']);
        $this->assertSame(55000, $slot['price_minor']);

        $manager->setPortal($admin, 'fixed', '50');
        $schedule = $days->forDate($venue, $court, $date->toDateString(), '12:00', 60);
        $slot = collect($schedule['slots'])->firstWhere('starts_at', '12:00');
        $this->assertSame(55000, $slot['price_minor']);
        $this->assertSame(100000, $schedule['price_preview']['provider_amount_minor']);
        $this->assertSame(5000, $schedule['price_preview']['portal_fee_minor']);
        $this->assertSame(105000, $schedule['price_preview']['total_minor']);

        // Published court weekly tariffs must not diverge from quote pricing.
        $court->slotPrices()->create([
            'day_of_week' => $date->dayOfWeekIso,
            'starts_at' => '12:00',
            'whole_price_per_step_minor' => 60000,
        ]);
        $withWeeklyOverride = $days->forDate($venue, $court, $date->toDateString(), '12:00', 60);
        $firstSlot = collect($withWeeklyOverride['slots'])->firstWhere('starts_at', '12:00');
        $this->assertSame(60000, $firstSlot['base_price_minor']);
        $this->assertSame(65000, $firstSlot['price_minor']);
        $this->assertSame(110000, $withWeeklyOverride['price_preview']['provider_amount_minor']);
        $this->assertSame(115000, $withWeeklyOverride['price_preview']['total_minor']);
    }

    public function test_fixed_fees_rounding_and_zero_prices(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $manager = app(VenueCommissionManager::class);
        $manager->setPortal($admin, 'fixed', '50');
        $agent = $manager->saveAgent($venue, $admin, $this->activeUser(), 'fixed', '10');
        $manager->setVenue($venue, $admin, 'inherit', selectedAgentId: $agent->id);

        $calc = app(VenueCommissionCalculator::class);
        $result = $calc->calculate($venue, $court, 200000, 'RUB');
        $this->assertSame(5000, $result['portal_fee_minor']);
        $this->assertSame(1000, $result['agent_fee_minor']);
        $this->assertSame(206000, $result['total_minor']);

        $manager->setPortal($admin, 'percent', '2.5');
        $manager->saveAgent($venue, $admin, $agent->user, 'percent', '1.5');
        $rounded = $calc->calculate($venue, $court, 105, 'RUB');
        $this->assertSame(3, $rounded['portal_fee_minor']);
        $this->assertSame(2, $rounded['agent_fee_minor']);
        $this->assertSame(110, $rounded['total_minor']);

        $free = $calc->calculate($venue, $court, 0, 'RUB');
        $this->assertSame(0, $free['total_minor']);
        $this->assertSame(0, $free['agent_fee_minor']);
    }

    public function test_deactivating_selected_agent_removes_its_fee_and_no_staff_membership_implies_fee(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $manager = app(VenueCommissionManager::class);
        $user = $this->activeUser();
        $agent = $manager->saveAgent($venue, $admin, $user, 'percent', '5');
        $manager->setPortal($admin, 'percent', '10');

        $calc = app(VenueCommissionCalculator::class);
        $this->assertSame(220000, $calc->calculate($venue, $court, 200000, 'RUB')['total_minor']);
        $manager->setVenue($venue, $admin, 'inherit', selectedAgentId: $agent->id);
        $this->assertSame(230000, $calc->calculate($venue, $court, 200000, 'RUB')['total_minor']);
        $manager->saveAgent($venue, $admin, $user, 'percent', '5', false);
        $this->assertNull($venue->commissionPolicy()->firstOrFail()->selected_agent_id);
        $this->assertSame(220000, $calc->calculate($venue, $court, 200000, 'RUB')['total_minor']);
    }

    public function test_invalid_rate_and_foreign_agent_are_rejected_and_only_superadmin_can_modify(): void
    {
        [$admin, $venue] = $this->scenario();
        $manager = app(VenueCommissionManager::class);
        foreach (['101', '-1', 'eval(1)', '5.123'] as $bad) {
            try {
                $manager->setPortal($admin, 'percent', $bad);
                $this->fail('Invalid percent must be rejected.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseCount('portal_commission_policies', 0);

        $otherVenue = Venue::factory()->create();
        $foreignAgent = $manager->saveAgent($otherVenue, $admin, $this->activeUser(), 'percent', '5');
        try {
            $manager->setVenue($venue, $admin, 'inherit', selectedAgentId: $foreignAgent->id);
            $this->fail('Foreign venue agent must be rejected.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $user = $this->activeUser();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $manager->setPortal($user, 'percent', '10');
    }

    public function test_json_quote_and_html_quote_expose_same_final_amount_and_fee_breakdown(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $manager = app(VenueCommissionManager::class);
        $manager->setPortal($admin, 'percent', '10');
        $agent = $manager->saveAgent($venue, $admin, $this->activeUser(), 'percent', '5');
        $manager->setVenue($venue, $admin, 'inherit', selectedAgentId: $agent->id);
        $this->actingAs($admin)->postJson(route('venues.rental.quote', $venue->id), [
            'venue_court_id' => $court->id, 'starts_at' => '2026-08-26T12:00',
            'duration_minutes' => 120, 'scope' => 'whole',
        ])->assertOk()
            ->assertJsonPath('amount_minor', 230000)
            ->assertJsonPath('pricing_breakdown.provider_amount_minor', 200000)
            ->assertJsonPath('pricing_breakdown.portal_fee_minor', 20000)
            ->assertJsonPath('pricing_breakdown.agent_fee_minor', 10000)
            ->assertJsonPath('pricing_breakdown.total_minor', 230000);

        \Illuminate\Support\Facades\View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $this->actingAs($admin)->post(route('venues.rental.quote', $venue->id), [
            'venue_court_id' => $court->id, 'starts_at' => '2026-08-26T12:00',
            'duration_minutes' => 120, 'scope' => 'whole',
        ])->assertOk()
            ->assertSeeText('Комиссия MSKBA')
            ->assertSeeText('Комиссия агента')
            ->assertSeeText('Итого: 2300');
    }

    /** @return array{User,Venue,VenueCourt} */
    private function scenario(): array
    {
        $admin = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE,
        ]);
        $venue->schedule()->create(['timezone' => 'Europe/Moscow']);
        $court = $venue->primaryCourt()->firstOrFail();
        VenueBookingPolicy::query()->create([
            'venue_id' => $venue->id,
            'version' => 1, 'active_marker' => true, 'is_enabled' => true,
            'allows_whole' => true, 'allows_halves' => false,
            'minimum_duration_minutes' => 60, 'maximum_duration_minutes' => 240,
            'time_step_minutes' => 30, 'minimum_lead_time_minutes' => 0,
            'maximum_advance_days' => 90, 'currency' => 'RUB',
            'whole_price_per_step_minor' => 50000, 'half_price_per_step_minor' => 30000,
            'hold_duration_minutes' => 30, 'requires_payment' => true,
            'payment_window_minutes' => 30, 'quote_validity_minutes' => 15,
            'published_by_user_id' => $admin->id, 'published_at' => now(),
        ]);

        return [$admin, $venue, $court];
    }

    private function activeUser(): User
    {
        return User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
    }

    private function quote(Venue $venue, VenueCourt $court)
    {
        return app(QuoteVenueBookingHandler::class)->handle(
            $venue, CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow'),
            120, VenueBookingScopeEnum::WHOLE, court: $court,
        );
    }
}
