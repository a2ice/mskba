<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\VenueBooking;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Application\Services\VenueBookingConflictService;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingConflictException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking as RentalVenueBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class VenueCourtBookingIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_whole_booking_blocks_same_court_but_not_another_court(): void
    {
        [$venue, $courtA, $courtB] = $this->venueWithTwoCourts();
        [$startsAt, $endsAt] = $this->interval();
        $this->booking($venue, $courtA, VenueBookingScopeEnum::WHOLE, $startsAt, $endsAt);

        app(VenueEventAvailability::class)->assertAvailable(
            $venue,
            $startsAt,
            $endsAt,
            scope: VenueBookingScopeEnum::WHOLE,
            court: $courtB,
        );
        $this->addToAssertionCount(1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Выбранное время уже занято');
        app(VenueEventAvailability::class)->assertAvailable(
            $venue,
            $startsAt,
            $endsAt,
            scope: VenueBookingScopeEnum::WHOLE,
            court: $courtA,
        );
    }

    public function test_booking_lifecycle_conflicts_are_isolated_by_court(): void
    {
        [$venue, $courtA, $courtB] = $this->venueWithTwoCourts();
        [$startsAt, $endsAt] = $this->interval();
        $this->booking($venue, $courtA, VenueBookingScopeEnum::WHOLE, $startsAt, $endsAt);

        $otherCourtCandidate = $this->rentalCandidate($venue, $courtB, $startsAt, $endsAt);
        app(VenueBookingConflictService::class)->lockAndAssertAvailable($venue, $otherCourtCandidate);
        $this->addToAssertionCount(1);

        $this->expectException(VenueBookingConflictException::class);
        app(VenueBookingConflictService::class)->lockAndAssertAvailable(
            $venue,
            $this->rentalCandidate($venue, $courtA, $startsAt, $endsAt),
        );
    }

    public function test_halves_conflict_only_inside_same_court_and_follow_scope_rules(): void
    {
        [$venue, $courtA, $courtB] = $this->venueWithTwoCourts(true);
        [$startsAt, $endsAt] = $this->interval();
        $this->booking($venue, $courtA, VenueBookingScopeEnum::HALF_A, $startsAt, $endsAt);

        app(VenueEventAvailability::class)->assertAvailable(
            $venue,
            $startsAt,
            $endsAt,
            scope: VenueBookingScopeEnum::HALF_B,
            court: $courtA,
        );
        app(VenueEventAvailability::class)->assertAvailable(
            $venue,
            $startsAt,
            $endsAt,
            scope: VenueBookingScopeEnum::WHOLE,
            court: $courtB,
        );
        $this->addToAssertionCount(2);

        $this->expectException(InvalidArgumentException::class);
        app(VenueEventAvailability::class)->assertAvailable(
            $venue,
            $startsAt,
            $endsAt,
            scope: VenueBookingScopeEnum::WHOLE,
            court: $courtA,
        );
    }

    public function test_half_booking_requires_selected_court_to_support_halves(): void
    {
        [$venue, , $courtB] = $this->venueWithTwoCourts(false);
        [$startsAt, $endsAt] = $this->interval();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('зал не поддерживает');
        app(VenueEventAvailability::class)->assertAvailable(
            $venue,
            $startsAt,
            $endsAt,
            scope: VenueBookingScopeEnum::HALF_A,
            court: $courtB,
        );
    }

    public function test_legacy_booking_without_court_remains_conservatively_venue_wide(): void
    {
        [$venue, , $courtB] = $this->venueWithTwoCourts();
        [$startsAt, $endsAt] = $this->interval();
        DB::table('venue_bookings')->insert([
            'venue_id' => $venue->id,
            'venue_court_id' => null,
            'event_id' => null,
            'created_by_actor_id' => $venue->created_by_actor_id,
            'status' => VenueBookingStatusEnum::CONFIRMED->value,
            'scope' => VenueBookingScopeEnum::WHOLE->value,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(VenueEventAvailability::class)->assertAvailable(
            $venue,
            $startsAt,
            $endsAt,
            scope: VenueBookingScopeEnum::WHOLE,
            court: $courtB,
        );
    }

    /** @return array{Venue, VenueCourt, VenueCourt} */
    private function venueWithTwoCourts(bool $supportsHalves = true): array
    {
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE,
        ]);
        $courtA = $venue->primaryCourt()->firstOrFail();
        $courtA->update(['supports_halves' => $supportsHalves]);
        $courtB = VenueCourt::query()->create([
            'venue_id' => $venue->id,
            'name' => 'Зал 2',
            'alias' => 'zal-2',
            'sort_order' => 20,
            'is_primary' => false,
            'supports_halves' => $supportsHalves,
        ]);

        return [$venue, $courtA->fresh(), $courtB];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function interval(): array
    {
        $startsAt = CarbonImmutable::now(config('app.timezone'))->addDay()->setTime(18, 0, 0);

        return [$startsAt, $startsAt->addHour()];
    }

    private function booking(
        Venue $venue,
        VenueCourt $court,
        VenueBookingScopeEnum $scope,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): VenueBooking {
        return VenueBooking::query()->create([
            'venue_id' => $venue->id,
            'venue_court_id' => $court->id,
            'created_by_actor_id' => $venue->created_by_actor_id,
            'status' => VenueBookingStatusEnum::CONFIRMED,
            'scope' => $scope,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    private function rentalCandidate(
        Venue $venue,
        VenueCourt $court,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): RentalVenueBooking {
        $candidate = new RentalVenueBooking();
        $candidate->forceFill([
            'venue_id' => $venue->id,
            'venue_court_id' => $court->id,
            'scope' => VenueBookingScopeEnum::WHOLE,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'quote_snapshot' => ['policy' => ['time_step_minutes' => 1]],
        ]);

        return $candidate;
    }
}
