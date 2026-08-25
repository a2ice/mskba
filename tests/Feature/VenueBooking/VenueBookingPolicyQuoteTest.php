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
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\UseCases\PublishVenueBookingPolicyHandler;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Models\VenueBookingQuote;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class VenueBookingPolicyQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
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

    public function test_half_quote_requires_both_policy_permission_and_physical_zones(): void
    {
        [$owner, $oneHoopVenue] = $this->ownedVenue(1);
        $publish = app(PublishVenueBookingPolicyHandler::class);

        try {
            $publish->handle($oneHoopVenue, $owner, $this->policyData(['allows_halves' => true]));
            $this->fail('One-hoop venue must not publish split rental.');
        } catch (VenueBookingPolicyException) {
            $this->assertDatabaseCount('venue_booking_policies', 0);
        }

        [$secondOwner, $twoHoopVenue] = $this->ownedVenue(2);
        $publish->handle($twoHoopVenue, $secondOwner, $this->policyData(['allows_halves' => false]));

        $this->expectException(VenueBookingPolicyException::class);
        app(QuoteVenueBookingHandler::class)->handle(
            $twoHoopVenue,
            CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow'),
            60,
            VenueBookingScopeEnum::HALF_A,
        );
    }

    public function test_user_without_commercial_permission_cannot_publish_policy(): void
    {
        [, $venue] = $this->ownedVenue(2);
        $stranger = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->expectException(VenueBookingPolicyException::class);
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $stranger, $this->policyData());
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
}
