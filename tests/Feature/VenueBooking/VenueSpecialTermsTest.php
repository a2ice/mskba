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
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Application\Services\VenueGroupOffers;
use App\Modules\PlayInterest\Domain\Models\VenueGroupOffer;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Application\Services\VenueCommissionManager;
use App\Modules\VenueBooking\Application\Services\VenuePersonalPricing;
use App\Modules\VenueBooking\Application\Services\VenueSpecialTermsManager;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RequestVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Modules\VenueBooking\Domain\Models\VenueBookingQuote;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VenueSpecialTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        config()->set('features.venue_rental.special_terms', true);
        Carbon::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'status' => ContractStatusEnum::ACTIVE,
            'starts_at' => now()->subMinute(),
            'assigner' => UserParticipationRoleAssignerEnum::OTHER,
        ]);
        $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::VENUE,
            'scope_id' => $venue->id, 'user_id' => $admin->id, 'access_level' => 'owner',
        ]);

        return [$admin, $venue, $court];
    }

    private function activeUser(): User
    {
        return User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
    }

    private function offer(User $admin, Venue $venue, User $recipient, array $overrides = [])
    {
        $manager = app(VenueSpecialTermsManager::class);
        $offer = $manager->create($venue, $admin, [
            'title' => 'Персональные условия', 'description' => '<script>alert(1)</script> Постоянный клиент',
            'audience' => 'USER_BOUND', 'recipient_ids' => [$recipient->id], 'auto_apply' => true,
            'code' => 'SPECIAL_'.Str::upper(Str::random(10)),
            'discount_type' => 'percent', 'discount_value' => 10, ...$overrides,
        ]);
        $manager->transition($venue, $admin, $offer, 'publish');

        return $offer->fresh();
    }

    private function quoteFor(Venue $venue, VenueCourt $court, ?User $user, ?string $code = null)
    {
        return app(QuoteVenueBookingHandler::class)->handle($venue,
            CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow'),
            120, VenueBookingScopeEnum::WHOLE, $user, $court, $code);
    }

    private function requestQuote(User $user, string $quoteId)
    {
        $actor = app(CurrentActorResolver::class)->resolve($user, null);

        return app(RequestVenueBookingHandler::class)->handle($actor, $quoteId);
    }

    public function test_personal_stage_runs_after_legacy_commissions_without_changing_their_meaning(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $client = $this->activeUser();
        $manager = app(VenueCommissionManager::class);
        $manager->setPortal($admin, 'percent', '10');
        $agent = $manager->saveAgent($venue, $admin, $this->activeUser(), 'percent', '5');
        $manager->setVenue($venue, $admin, 'inherit', selectedAgentId: $agent->id);
        $this->offer($admin, $venue, $client);
        $quote = $this->quoteFor($venue, $court, $client);
        $this->assertSame(207000, $quote->amountMinor);
        $this->assertSame(230000, $quote->personalDiscount['original_amount_minor']);
        $this->assertSame(20000, $quote->commissionBreakdown['portal_fee_minor']);
        $this->assertSame(10000, $quote->commissionBreakdown['agent_fee_minor']);
        $this->assertSame('venue', $quote->personalDiscount['funded_by']);
        $this->assertSame(230000, $this->quoteFor($venue, $court, $admin)->amountMinor);
    }

    public function test_leaked_bound_code_fails_for_wrong_user_guest_and_unconfirmed_account(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $client = $this->activeUser();
        $this->offer($admin, $venue, $client, ['code' => 'PRIVATE10', 'auto_apply' => false]);
        foreach ([$admin, null, User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED])] as $user) {
            try {
                $this->quoteFor($venue, $court, $user, 'PRIVATE10');
                $this->fail('Leaked code must fail.');
            } catch (VenueBookingPolicyException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(180000, $this->quoteFor($venue, $court, $client, ' private10 ')->amountMinor);
        $this->assertSame(200000, $this->quoteFor($venue, $court, $client)->amountMinor);
    }

    public function test_public_codes_work_for_confirmed_accounts_only_and_group_offers_remain_private(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $this->offer($admin, $venue, $admin, ['audience' => 'PUBLIC', 'auto_apply' => false, 'code' => 'PUBLIC10']);
        $client = $this->activeUser();
        $this->assertSame(180000, $this->quoteFor($venue, $court, $client, 'PUBLIC10')->amountMinor);
        $this->assertSame(200000, $this->quoteFor($venue, $court, $client)->amountMinor);
        $this->assertSame(0, VenueGroupOffer::query()
            ->where('group_key', VenueGroupOffer::groupKey($venue))->count());
        $group = app(VenueGroupOffers::class)->create($venue, $admin, [
            'title' => 'Групповая скидка', 'code' => 'GROUP10', 'discount_type' => 'percent', 'discount_value' => 10,
            'valid_from' => '2026-08-25T10:00', 'timezone' => 'Europe/Moscow',
        ]);
        app(VenueGroupOffers::class)->transition($group, $admin, 'publish');
        $this->expectException(VenueBookingPolicyException::class);
        $this->quoteFor($venue, $court, $client, 'GROUP10');
    }

    public function test_best_discount_wins_without_stacking_and_fixed_total_is_supported(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $client = $this->activeUser();
        $this->offer($admin, $venue, $client);
        $this->offer($admin, $venue, $client, ['discount_type' => 'fixed', 'discount_value' => 30000]);
        $best = $this->offer($admin, $venue, $client, ['discount_type' => 'fixed_total', 'discount_value' => 150001]);
        $quote = $this->quoteFor($venue, $court, $client);
        $this->assertSame(150001, $quote->amountMinor);
        $this->assertSame($best->id, $quote->personalDiscount['offer_id']);
        $this->assertSame(49999, $quote->personalDiscount['discount_minor']);
    }

    public function test_invalid_context_expiration_currency_and_nonpositive_price_never_apply(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $client = $this->activeUser();
        $other = Venue::factory()->create()->primaryCourt()->firstOrFail();
        $cases = [
            ['valid_until' => now()->subSecond()], ['valid_from' => now()->addDay()],
            ['scope' => 'half_a'], ['venue_court_id' => $other->id], ['currency' => 'USD'],
            ['minimum_amount_minor' => 200001], ['max_total_uses' => 1, 'used_count' => 1],
            ['booking_conditions' => ['booking_date_from' => '2026-08-27']],
            ['booking_conditions' => ['booking_date_to' => '2026-08-25']],
            ['booking_conditions' => ['weekdays' => [1]]],
            ['booking_conditions' => ['time_from' => '12:00', 'time_to' => '13:00']],
            ['discount_type' => 'fixed', 'discount_value' => 200000],
            ['discount_type' => 'fixed', 'discount_value' => 200001],
        ];
        foreach ($cases as $case) {
            $offer = $this->offer($admin, $venue, $client);
            // Simulate lifecycle/time/context changes after publication.
            $offer->forceFill($case)->save();
            $this->assertSame(200000, $this->quoteFor($venue, $court, $client)->amountMinor);
            try {
                $this->quoteFor($venue, $court, $client, $offer->code_ciphertext);
                $this->fail('Invalid contextual code must fail.');
            } catch (VenueBookingPolicyException) {
                $this->assertTrue(true);
            }
            $offer->forceFill(['status' => 'revoked'])->save();
        }
    }

    public function test_full_interval_context_and_integer_half_up_rounding(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $client = $this->activeUser();
        $offer = $this->offer($admin, $venue, $client, [
            'booking_date_from' => '2026-08-26', 'booking_date_to' => '2026-08-26',
            'time_from' => '12:00', 'time_to' => '14:00', 'weekdays' => [3],
        ]);
        $result = app(VenuePersonalPricing::class)->evaluate(
            $venue, $court, $client, CarbonImmutable::parse('2026-08-26 12:00', 'Europe/Moscow'),
            120, VenueBookingScopeEnum::WHOLE, 105, 'RUB');
        $this->assertSame(11, $result['discount_minor']);
        $this->assertSame(94, $result['final_amount_minor']);
        $this->assertSame(180000, $this->quoteFor($venue, $court, $client)->amountMinor);
        $offer->forceFill(['booking_conditions' => ['time_from' => '12:00', 'time_to' => '13:59']])->save();
        $this->assertSame(200000, $this->quoteFor($venue, $court, $client)->amountMinor);
    }

    public function test_quote_and_booking_snapshots_remain_immutable_and_retry_does_not_consume_twice(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $client = $this->activeUser();
        $offer = $this->offer($admin, $venue, $client, ['max_total_uses' => 1]);
        $quote = $this->quoteFor($venue, $court, $client);
        $booking = $this->requestQuote($client, $quote->publicId);
        $retry = $this->requestQuote($client, $quote->publicId);
        $this->assertSame($booking->id, $retry->id);
        $this->assertSame(1, $offer->fresh()->used_count);
        $this->assertDatabaseCount('venue_offer_redemptions', 1);
        $snapshot = $booking->quote_snapshot;
        app(VenueSpecialTermsManager::class)->transition($venue, $admin, $offer, 'revoke');
        $this->assertSame($snapshot, $booking->fresh()->quote_snapshot);
        $this->assertSame(180000, VenueBookingQuote::query()->where('public_id', $quote->publicId)->firstOrFail()->amount_minor);
        $this->assertSame(200000, $this->quoteFor($venue, $court, $client)->amountMinor);
    }

    public function test_competing_preissued_quotes_cannot_spend_the_last_use_twice(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $client = $this->activeUser();
        $this->offer($admin, $venue, $client, ['max_total_uses' => 1]);
        $first = $this->quoteFor($venue, $court, $client);
        $second = $this->quoteFor($venue, $court, $client);
        $this->requestQuote($client, $first->publicId);
        try {
            $this->requestQuote($client, $second->publicId);
            $this->fail('Exhausted quota must reject the preissued quote.');
        } catch (VenueBookingTransitionException $error) {
            $this->assertSame('PROMOTION_UNAVAILABLE', $error->errorCode);
        }
        $this->assertDatabaseCount('venue_offer_redemptions', 1);
        $this->assertSame(1, VenueBooking::query()->where('flow', 'rental')->count());
    }

    public function test_per_user_limit_is_rechecked_at_redemption_without_exhausting_other_recipients(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $client = $this->activeUser();
        $other = $this->activeUser();
        $offer = $this->offer($admin, $venue, $client, ['max_uses_per_account' => 1, 'recipient_ids' => [$client->id, $other->id]]);
        $first = $this->quoteFor($venue, $court, $client);
        $second = $this->quoteFor($venue, $court, $client);
        $this->requestQuote($client, $first->publicId);
        try {
            $this->requestQuote($client, $second->publicId);
            $this->fail('Per-user quota must reject redemption.');
        } catch (VenueBookingTransitionException $error) {
            $this->assertSame('PROMOTION_UNAVAILABLE', $error->errorCode);
        }
        $this->assertSame(180000, $this->quoteFor($venue, $court, $other)->amountMinor);
        $this->assertSame(1, $offer->fresh()->used_count);
    }

    public function test_pause_after_quote_rejects_request_without_mutating_quote_or_creating_booking(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $client = $this->activeUser();
        $offer = $this->offer($admin, $venue, $client);
        $quote = $this->quoteFor($venue, $court, $client);
        app(VenueSpecialTermsManager::class)->transition($venue, $admin, $offer, 'pause');
        try {
            $this->requestQuote($client, $quote->publicId);
            $this->fail('Paused terms must reject request.');
        } catch (VenueBookingTransitionException $error) {
            $this->assertSame('PROMOTION_UNAVAILABLE', $error->errorCode);
        }
        $this->assertDatabaseCount('venue_offer_redemptions', 0);
        $this->assertSame(0, VenueBooking::query()->where('flow', 'rental')->count());
    }

    public function test_disabled_feature_keeps_nonpromoted_quote_math_and_snapshot_schema(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $this->offer($admin, $venue, $admin);
        config()->set('features.venue_rental.special_terms', false);
        $quote = $this->quoteFor($venue, $court, $admin);
        $this->assertSame(200000, $quote->amountMinor);
        $this->assertNull($quote->personalDiscount);
        $row = VenueBookingQuote::query()->where('public_id', $quote->publicId)->firstOrFail();
        $this->assertSame(4, $row->snapshot['schema_version']);
        $this->assertArrayNotHasKey('personal_discount', $row->snapshot['pricing']);
    }

    public function test_management_authorization_validation_and_wrong_venue_are_enforced(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $client = $this->activeUser();
        $manager = app(VenueSpecialTermsManager::class);
        $this->assertFalse($manager->canManage($client, $venue));
        $this->actingAs($client)->get(route('account.venues.special-terms.index', $venue))->assertForbidden();
        $this->actingAs($client)->post(route('account.venues.special-terms.store', $venue), [])->assertForbidden();
        $offer = $this->offer($admin, $venue, $client);
        $foreignVenue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED, 'operational_status' => VenueOperationalStatusEnum::ACTIVE]);
        $this->actingAs($admin)->post(route('account.venues.special-terms.transition', [$foreignVenue->id, $offer->id, 'pause']))->assertForbidden();
        $this->actingAs($admin)->post(route('account.venues.special-terms.store', $venue), [
            'title' => 'Плохое условие', 'description' => 'Проверка', 'audience' => 'USER_BOUND',
            'recipient_ids' => [$client->id], 'discount_type' => 'percent', 'discount_value' => 100,
        ])->assertSessionHasErrors('discount_value');
        $this->assertSame(['view', 'rental.interests.view'], array_map(fn ($p) => $p->value,
            VenueMembershipAccessLevelEnum::AGENT->defaultPermissions()));
    }

    public function test_superadmin_uses_explicit_administration_and_cannot_manage_unrelated_venue_in_account(): void
    {
        [$owner, $venue] = $this->scenario();
        $admin = User::factory()->create(['system_role' => UserSystemRoleEnum::SUPERADMIN, 'status' => UserStatusEnum::CONFIRMED]);
        $manager = app(VenueSpecialTermsManager::class);
        $this->assertFalse($manager->canManage($admin, $venue));
        $this->assertTrue($manager->canManage($admin, $venue, administration: true));
        $this->actingAs($admin)->get(route('account.venues.special-terms.index', $venue))->assertForbidden();
    }

    public function test_valid_explicit_coupon_competes_with_auto_terms_but_invalid_code_never_falls_back(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $client = $this->activeUser();
        $best = $this->offer($admin, $venue, $client, ['discount_value' => 20]);
        $this->offer($admin, $venue, $client, ['audience' => 'PUBLIC', 'auto_apply' => false, 'code' => 'SMALL10']);
        $quote = $this->quoteFor($venue, $court, $client, 'SMALL10');
        $this->assertSame(160000, $quote->amountMinor);
        $this->assertSame($best->id, $quote->personalDiscount['offer_id']);
        $this->expectException(VenueBookingPolicyException::class);
        $this->quoteFor($venue, $court, $client, 'UNKNOWN10');
    }

    public function test_wrong_venue_code_and_wrong_user_quote_cannot_create_redemptions(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        [$otherAdmin, $otherVenue, $otherCourt] = $this->scenario();
        $client = $this->activeUser();
        $this->offer($admin, $venue, $client, ['code' => 'THISVENUE']);
        try {
            $this->quoteFor($otherVenue, $otherCourt, $client, 'THISVENUE');
            $this->fail('A code cannot cross venues.');
        } catch (VenueBookingPolicyException) {
            $this->assertTrue(true);
        }
        $quote = $this->quoteFor($venue, $court, $client);
        try {
            $this->requestQuote($otherAdmin, $quote->publicId);
            $this->fail('A personal quote cannot cross users.');
        } catch (VenueBookingTransitionException $error) {
            $this->assertSame('QUOTE_FORBIDDEN', $error->errorCode);
        }
        $this->assertDatabaseCount('venue_offer_redemptions', 0);
    }

    public function test_offer_expiring_before_quote_does_not_consume_usage_or_change_stored_price(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $client = $this->activeUser();
        $this->offer($admin, $venue, $client, ['valid_until' => now()->addMinute()->toIso8601String()]);
        $quote = $this->quoteFor($venue, $court, $client);
        Carbon::setTestNow(now()->addMinutes(2));
        try {
            $this->requestQuote($client, $quote->publicId);
            $this->fail('Expired offer must reject redemption.');
        } catch (VenueBookingTransitionException $error) {
            $this->assertSame('PROMOTION_UNAVAILABLE', $error->errorCode);
        }
        $this->assertDatabaseCount('venue_offer_redemptions', 0);
        $this->assertSame(180000, VenueBookingQuote::query()->where('public_id', $quote->publicId)->firstOrFail()->amount_minor);
    }

    public function test_day_preview_and_quote_have_identical_personal_final_prices_and_hide_fees(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $client = $this->activeUser();
        $venue->schedule()->firstOrFail()->intervals()->create([
            'day_of_week' => 3, 'starts_at' => '07:00', 'ends_at' => '23:59', 'sort_order' => 0,
        ]);
        app(VenueCommissionManager::class)->setPortal($admin, 'fixed', '50');
        $this->offer($admin, $venue, $client);
        $quote = $this->quoteFor($venue, $court, $client);
        $this->actingAs($client)->getJson(route('venues.schedule.day', [
            'venue' => $venue->id, 'venue_court_id' => $court->id, 'date' => '2026-08-26',
            'preview_start' => '12:00', 'preview_duration_minutes' => 120,
        ]))->assertOk()->assertJsonPath('price_preview.total_minor', $quote->amountMinor)
            ->assertJsonPath('price_preview.personal_discount.original_amount_minor', 205000)
            ->assertJsonMissingPath('price_preview.portal_fee_minor')
            ->assertJsonMissingPath('slots.0.pricing_breakdown');
    }

    public function test_management_rejects_unconfirmed_recipients_foreign_courts_and_unsupported_funding(): void
    {
        [$admin, $venue] = $this->scenario();
        $client = $this->activeUser();
        $unconfirmed = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        $foreignCourt = Venue::factory()->create()->primaryCourt()->firstOrFail();
        $data = ['title' => 'Специальное условие', 'description' => 'Причина', 'audience' => 'USER_BOUND',
            'recipient_ids' => [$client->id], 'discount_type' => 'percent', 'discount_value' => 10];
        foreach ([
            [['recipient_ids' => [$unconfirmed->id]], 'recipient_ids'],
            [['venue_court_id' => $foreignCourt->id], 'venue_court_id'],
            [['funded_by' => 'agent'], 'funded_by'],
            [['conversation_id' => 1], 'conversation_id'],
            [['time_from' => ['12:00']], 'time_from'],
        ] as [$overrides, $field]) {
            $this->actingAs($admin)->post(route('account.venues.special-terms.store', $venue), [...$data, ...$overrides])
                ->assertSessionHasErrors($field);
        }
        $this->assertDatabaseCount('venue_group_offers', 0);
    }

    public function test_scoped_agent_delegation_is_explicit_and_expired_contract_removes_access(): void
    {
        [$admin, $venue] = $this->scenario();
        $agent = $this->activeUser();
        $manager = app(VenueSpecialTermsManager::class);
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'status' => ContractStatusEnum::ACTIVE,
            'starts_at' => now()->subMinute(),
            'assigner' => UserParticipationRoleAssignerEnum::OTHER,
        ]);
        $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::VENUE,
            'scope_id' => $venue->id, 'user_id' => $agent->id, 'access_level' => 'agent',
        ]);
        $this->assertFalse($manager->canManage($agent, $venue));
        $contract->permissions()->create(['permission' => VenuePermissionEnum::MANAGE_SPECIAL_TERMS->value]);
        $this->assertTrue($manager->canManage($agent, $venue));
        $foreignVenue = Venue::factory()->create();
        $this->assertFalse($manager->canManage($agent, $foreignVenue));
        $contract->update(['expires_at' => now()->subSecond()]);
        $this->assertFalse($manager->canManage($agent, $venue));
    }

    public function test_public_json_and_html_prices_are_private_safe_and_match_preview(): void
    {
        [$admin, $venue, $court] = $this->scenario();
        $client = $this->activeUser();
        $this->offer($admin, $venue, $client);
        $data = ['venue_court_id' => $court->id, 'starts_at' => '2026-08-26T12:00', 'duration_minutes' => 120, 'scope' => 'whole'];
        $this->actingAs($client)->postJson(route('venues.rental.quote', $venue->id), $data)
            ->assertOk()->assertJsonPath('amount_minor', 180000)
            ->assertJsonPath('personal_discount.original_amount_minor', 200000)
            ->assertJsonMissingPath('pricing_breakdown')->assertJsonMissingPath('personal_discount.recipient_user_id')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($admin)->post(route('account.venues.special-terms.preview', $venue), [...$data, 'recipient_id' => $client->id])
            ->assertRedirect()->assertSessionHas('special_preview.amount_minor', 180000);
        foreach (['mskba_app', 'mskba_dark'] as $theme) {
            View::replaceNamespace('theme', resource_path('themes/'.$theme.'/views'));
            $this->actingAs($client)->post(route('venues.rental.quote', $venue->id), $data)->assertOk()
                ->assertSee('<del>', false)->assertDontSee('<script>alert(1)</script>', false)
                ->assertDontSeeText('Комиссия MSKBA')->assertDontSeeText('Комиссия агента');
            $this->actingAs($admin)->get(route('account.venues.special-terms.index', $venue))
                ->assertOk()->assertSeeText('Специальные условия');
        }
    }
}
