<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\EffectiveCourtPolicy;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Application\UseCases\ManageVenueMembershipHandler;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Application\UseCases\PublishVenueBookingPolicyHandler;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingQuote;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class VenueCourtInheritedPolicyAccessTest extends TestCase
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

    public function test_parent_access_and_per_court_scopes_never_escalate_to_other_courts(): void
    {
        [$owner, $venue] = $this->ownedVenue();
        $first = $venue->primaryCourt()->firstOrFail();
        $second = $venue->courts()->create([
            'name' => 'Школьный зал', 'alias' => 'indoor', 'sort_order' => 20,
            'allows_whole' => true,
        ]);
        $operator = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $handler = app(ManageVenueMembershipHandler::class);
        $membership = $handler->grant(
            $venue, $operator, VenueMembershipAccessLevelEnum::BOOKING_OPERATOR,
            $owner, null, $first,
        );
        $access = app(VenueCommercialAccess::class);
        $this->assertSame(ContractMembershipScopeTypeEnum::VENUE_COURT, $membership->scope_type);
        $this->assertTrue($access->allowsForCourt($operator, $first, VenuePermissionEnum::DECIDE_BOOKING_REQUESTS));
        $this->assertFalse($access->allowsForCourt($operator, $second, VenuePermissionEnum::DECIDE_BOOKING_REQUESTS));
        $this->assertFalse($access->allows($operator, $venue, VenuePermissionEnum::DECIDE_BOOKING_REQUESTS));
        $this->assertTrue($access->allowsForCourt($owner, $second, VenuePermissionEnum::DECIDE_BOOKING_REQUESTS));

        $actor = app(CurrentActorResolver::class)->resolve($operator, null);
        $authorize = app(VenueBookingAuthorization::class);
        $authorize->assertCommercialDecision($actor, $venue, new VenueBooking([
            'venue_id' => $venue->id, 'venue_court_id' => $first->id,
        ]));
        try {
            $authorize->assertCommercialDecision($actor, $venue, new VenueBooking([
                'venue_id' => $venue->id, 'venue_court_id' => $second->id,
            ]));
            $this->fail('Court operator must not decide other court booking.');
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame('BOOKING_FORBIDDEN', $exception->errorCode);
        }

        $handler->revoke($venue, $membership, $owner);
        $this->assertFalse($access->allowsForCourt($operator, $first, VenuePermissionEnum::DECIDE_BOOKING_REQUESTS));
    }

    public function test_court_scoped_pricing_manager_cannot_modify_sibling_court(): void
    {
        file_put_contents('/tmp/roomtest.markers', 'ROOMTEST-START'."\n", FILE_APPEND);
        [$owner, $venue] = $this->ownedVenue();
        file_put_contents('/tmp/roomtest.markers', 'ROOMTEST-OWNER'."\n", FILE_APPEND);
        $first = $venue->primaryCourt()->firstOrFail();
        $second = $venue->courts()->create([
            'name' => 'Другой зал', 'alias' => 'other', 'sort_order' => 20, 'allows_whole' => true,
        ]);
        file_put_contents('/tmp/roomtest.markers', 'ROOMTEST-COURTS'."\n", FILE_APPEND);
        $manager = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        file_put_contents('/tmp/roomtest.markers', 'ROOMTEST-MANAGER'."\n", FILE_APPEND);
        app(ManageVenueMembershipHandler::class)->grant(
            $venue, $manager, VenueMembershipAccessLevelEnum::MANAGER, $owner,
            [VenuePermissionEnum::MANAGE_BOOKING_POLICY], $first,
        );

        file_put_contents('/tmp/roomtest.markers', 'ROOMTEST-GRANTED'."\n", FILE_APPEND);
        $this->actingAs($manager)->put(
            route('account.venues.courts.settings.update', [$venue->routeIdentifier(), $second->routeIdentifier()]),
            ['requires_payment' => 'no'],
        )->assertForbidden();
        file_put_contents('/tmp/roomtest.markers', 'ROOMTEST-FOREIGN-PUT-PASSED'."\n", FILE_APPEND);
        $this->assertNull($second->refresh()->requires_payment);

        $this->actingAs($manager)->put(
            route('account.venues.courts.settings.update', [$venue->routeIdentifier(), $first->routeIdentifier()]),
            ['requires_payment' => 'no'],
        )->assertRedirect();
        file_put_contents('/tmp/roomtest.markers', 'ROOMTEST-OWN-PUT-PASSED'."\n", FILE_APPEND);
        $this->assertFalse($first->refresh()->requires_payment);

        $this->actingAs($manager)->post(
            route('account.venues.courts.prices.store', [$venue->routeIdentifier(), $second->routeIdentifier()]),
            ['day_of_week' => '3', 'starts_at' => '12:00', 'whole_price_per_step' => '700'],
        )->assertForbidden();
        file_put_contents('/tmp/roomtest.markers', 'ROOMTEST-FOREIGN-PRICE-PASSED'."\n", FILE_APPEND);
        $this->assertSame(0, $second->slotPrices()->count());
    }

    public function test_inherited_court_price_can_be_overridden_and_quotes_freeze_price_snapshots(): void
    {
        [$owner, $venue] = $this->ownedVenue();
        $court = $venue->primaryCourt()->firstOrFail();
        $policy = app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, $this->policyData());
        $start = CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow');
        $quote = app(QuoteVenueBookingHandler::class);

        $parentQuote = $quote->handle($venue, $start, 60, VenueBookingScopeEnum::WHOLE, $owner, $court);
        $this->assertSame(1000, $parentQuote->amountMinor);

        $court->update(['whole_price_per_step_minor' => 750]);
        $localQuote = $quote->handle($venue, $start, 60, VenueBookingScopeEnum::WHOLE, $owner, $court);
        $this->assertSame(1500, $localQuote->amountMinor);

        $court->slotPrices()->create([
            'day_of_week' => 3, 'starts_at' => '12:00',
            'whole_price_per_step_minor' => 1100,
        ]);
        $stepQuote = $quote->handle($venue, $start, 60, VenueBookingScopeEnum::WHOLE, $owner, $court);
        $this->assertSame(1850, $stepQuote->amountMinor);
        $snapshot = VenueBookingQuote::query()->where('public_id', $stepQuote->publicId)->firstOrFail()->snapshot;
        $this->assertSame([1100, 750], $snapshot['pricing']['prices_per_step_minor']);
        $this->assertTrue($snapshot['policy']['requires_payment']);

        $court->update(['requires_payment' => false]);
        $freeQuote = $quote->handle($venue, $start, 60, VenueBookingScopeEnum::WHOLE, $owner, $court);
        $this->assertSame(0, $freeQuote->amountMinor);
        $this->assertFalse($freeQuote->requiresPayment);
        $freeSnapshot = VenueBookingQuote::query()->where('public_id', $freeQuote->publicId)->firstOrFail()->snapshot;
        $this->assertSame(0, $freeSnapshot['pricing']['price_per_step_minor']);
        $this->assertSame([0, 0], $freeSnapshot['pricing']['prices_per_step_minor']);
        $this->assertSame(1850, VenueBookingQuote::query()->where('public_id', $stepQuote->publicId)->value('amount_minor'));
        $this->assertTrue(app(EffectiveCourtPolicy::class)->resolve($venue, $court, $policy)['rental_enabled']);
    }

    public function test_parent_closure_overrides_local_open_and_user_cannot_modify_foreign_court_prices(): void
    {
        [$owner, $venue] = $this->ownedVenue();
        $court = $venue->primaryCourt()->firstOrFail();
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, $this->policyData());
        $court->update(['operational_status' => VenueOperationalStatusEnum::ACTIVE]);
        $venue->update(['operational_status' => VenueOperationalStatusEnum::TEMPORARILY_CLOSED]);

        $this->assertFalse(app(EffectiveCourtPolicy::class)->resolve($venue, $court)['is_open']);
        $this->expectException(VenueBookingPolicyException::class);
        app(QuoteVenueBookingHandler::class)->handle(
            $venue, CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow'),
            60, VenueBookingScopeEnum::WHOLE, $owner, $court,
        );
    }

    /** @return array{User, Venue} */
    private function ownedVenue(): array
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create();
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
            fn (VenuePermissionEnum $permission): array => ['permission' => $permission->value],
            VenueMembershipAccessLevelEnum::OWNER->defaultPermissions(),
        ));

        return [$owner, $venue];
    }

    /** @return array<string, mixed> */
    private function policyData(): array
    {
        return [
            'is_enabled' => true, 'allows_whole' => true, 'allows_halves' => false,
            'minimum_duration_minutes' => 60, 'maximum_duration_minutes' => 180,
            'time_step_minutes' => 30, 'minimum_lead_time_minutes' => 120,
            'maximum_advance_days' => 90, 'currency' => 'RUB',
            'whole_price_per_step_minor' => 500, 'half_price_per_step_minor' => null,
            'hold_duration_minutes' => 30, 'requires_payment' => true,
            'payment_window_minutes' => 30, 'quote_validity_minutes' => 15,
            'cancellation_before_minutes' => 1440,
        ];
    }
}
