<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\SyncVenueCourtSchedule;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class VenueCourtManualSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_courts_have_disabled_automatic_sync_and_manual_defaults(): void
    {
        $venue = $this->venueWithSchedule();
        $court = $venue->primaryCourt()->firstOrFail();

        $this->assertFalse($court->auto_sync_enabled);
        $this->assertTrue($court->sync_include_prices_default);
        $this->assertSame(7, $court->sync_period_days);
        $this->assertFalse($court->schedule()->exists());

        // Changing the venue's week never writes or copies court data.
        $venue->schedule->intervals()->create([
            'day_of_week' => 3, 'starts_at' => '12:00',
            'ends_at' => '17:00', 'sort_order' => 0,
        ]);
        $this->assertFalse($court->schedule()->exists());
        $this->assertSame(0, $court->slotPrices()->count());
        $this->assertSame(0, $court->calendarSlotPrices()->count());
    }

    public function test_one_day_sync_replaces_only_that_day_and_snapshots_effective_prices(): void
    {
        $venue = $this->venueWithSchedule();
        $user = User::factory()->create();
        $this->policy($venue, $user);
        $court = $venue->primaryCourt()->firstOrFail();
        $court->update(['time_step_minutes' => 60, 'whole_price_per_step_minor' => 99999]);
        $court->schedule()->create([
            'weekly_intervals' => [
                ['day_of_week' => 1, 'starts_at' => '11:00', 'ends_at' => '16:00'],
            ],
            'exceptions' => [
                ['date' => '2026-11-09', 'is_closed' => true],
                ['date' => '2026-11-16', 'is_closed' => true],
            ],
        ]);
        $court->calendarSlotPrices()->create([
            'date' => '2026-11-16', 'starts_at' => '10:00',
            'whole_price_per_step_minor' => 12345,
        ]);
        $court->calendarSlotPrices()->create([
            'date' => '2026-11-09', 'starts_at' => '10:00',
            'whole_price_per_step_minor' => 111,
        ]);
        $venue->scheduleSlotPrices()->create([
            'day_of_week' => 1, 'starts_at' => '10:00',
            'whole_price_per_step_minor' => 700,
        ]);
        $venue->calendarSlotPrices()->create([
            'date' => '2026-11-09', 'starts_at' => '10:30',
            'whole_price_per_step_minor' => 900,
        ]);

        $result = app(SyncVenueCourtSchedule::class)->handle(
            $venue, [$court->id], 'day', '2026-11-09'
        );
        $this->assertSame(['courts' => 1, 'days' => 1, 'prices' => 24], $result);
        $rows = $court->calendarSlotPrices()->whereDate('date', '2026-11-09')->get();
        $this->assertCount(24, $rows);
        $morning = $rows->first(fn ($row): bool => substr((string) $row->starts_at, 0, 5) === '10:00');
        $this->assertSame(1600, $morning->whole_price_per_step_minor); // 700 + 900
        $this->assertSame(600, $morning->half_price_per_step_minor); // 300 + 300
        $this->assertDatabaseHas('venue_court_calendar_slot_prices', [
            'venue_court_id' => $court->id, 'date' => '2026-11-16',
            'whole_price_per_step_minor' => 12345,
        ]);
        $exceptions = collect($court->schedule()->firstOrFail()->exceptions);
        $this->assertSame('09:00', $exceptions->firstWhere('date', '2026-11-09')['starts_at']);
        $this->assertTrue($exceptions->firstWhere('date', '2026-11-16')['is_closed']);
        $this->assertSame('11:00', $court->schedule->weekly_intervals[0]['starts_at']);

        // Same selection is idempotent and unselected date remains untouched.
        app(SyncVenueCourtSchedule::class)->handle($venue, [$court->id], 'day', '2026-11-09');
        $this->assertSame(24, $court->calendarSlotPrices()->whereDate('date', '2026-11-09')->count());
        $this->assertSame(12345, $court->calendarSlotPrices()
            ->whereDate('date', '2026-11-16')->firstOrFail()->whole_price_per_step_minor);
    }

    public function test_period_sync_preserves_prices_when_checkbox_is_off(): void
    {
        $venue = $this->venueWithSchedule();
        $court = $venue->primaryCourt()->firstOrFail();
        $court->calendarSlotPrices()->create([
            'date' => '2026-11-10', 'starts_at' => '10:00',
            'whole_price_per_step_minor' => 777,
        ]);
        $court->schedule()->create([
            'weekly_intervals' => [['day_of_week' => 1, 'starts_at' => '12:00', 'ends_at' => '13:00']],
            'exceptions' => [['date' => '2026-11-21', 'is_closed' => true]],
        ]);
        $venue->schedule->exceptions()->create(['date' => '2026-11-10', 'is_closed' => true]);

        $result = app(SyncVenueCourtSchedule::class)->handle(
            $venue, [$court->id], 'period', '2026-11-09', '2026-11-15', false
        );
        $this->assertSame(['courts' => 1, 'days' => 7, 'prices' => 0], $result);
        $this->assertSame(777, $court->calendarSlotPrices()
            ->whereDate('date', '2026-11-10')->firstOrFail()->whole_price_per_step_minor);
        $dates = collect($court->schedule()->firstOrFail()->exceptions);
        $this->assertTrue($dates->firstWhere('date', '2026-11-10')['is_closed']);
        $this->assertTrue($dates->firstWhere('date', '2026-11-21')['is_closed']);
        $this->assertSame('12:00', $court->schedule()->firstOrFail()->weekly_intervals[0]['starts_at']);
    }

    public function test_full_sync_replaces_court_schedule_and_prices_as_independent_snapshot(): void
    {
        $venue = $this->venueWithSchedule();
        $user = User::factory()->create();
        $this->policy($venue, $user);
        $court = $venue->primaryCourt()->firstOrFail();
        $court->update(['time_step_minutes' => 60, 'whole_price_per_step_minor' => 99999]);
        $court->schedule()->create([
            'weekly_intervals' => [['day_of_week' => 2, 'starts_at' => '05:00', 'ends_at' => '08:00']],
            'exceptions' => [['date' => '2026-12-01', 'is_closed' => true]],
        ]);
        $court->slotPrices()->create([
            'day_of_week' => 2, 'starts_at' => '10:00',
            'whole_price_per_step_minor' => 99999,
        ]);
        $court->calendarSlotPrices()->create([
            'date' => '2026-12-01', 'starts_at' => '10:00',
            'whole_price_per_step_minor' => 99999,
        ]);
        $venue->scheduleSlotPrices()->create([
            'day_of_week' => 2, 'starts_at' => '11:30',
            'whole_price_per_step_minor' => 700,
        ]);
        $venue->calendarSlotPrices()->create([
            'date' => '2026-11-10', 'starts_at' => '11:30',
            'whole_price_per_step_minor' => 900,
        ]);
        $sourceException = $venue->schedule->exceptions()->create([
            'date' => '2026-11-11', 'is_closed' => false,
        ]);
        $sourceException->intervals()->create([
            'starts_at' => '13:00', 'ends_at' => '17:00', 'sort_order' => 0,
        ]);

        $result = app(SyncVenueCourtSchedule::class)->handle(
            $venue, [$court->id], 'full', includePrices: true
        );
        $this->assertSame(1, $result['courts']);
        $this->assertSame(0, $result['days']);
        $court->refresh();
        $this->assertSame(1000, $court->whole_price_per_step_minor); // 2 x 500
        $this->assertSame(600, $court->half_price_per_step_minor);
        $this->assertSame('09:00', $court->schedule->weekly_intervals[0]['starts_at']);
        $this->assertNull(collect($court->schedule->exceptions)->firstWhere('date', '2026-12-01'));
        $this->assertSame('13:00', collect($court->schedule->exceptions)
            ->firstWhere('date', '2026-11-11')['starts_at']);
        $weekly = $court->slotPrices()->where('day_of_week', 2)->get()
            ->first(fn ($row): bool => substr((string) $row->starts_at, 0, 5) === '11:00');
        $this->assertSame(1200, $weekly->whole_price_per_step_minor); // 500 + 700
        $calendar = $court->calendarSlotPrices()->get()->first(
            fn ($row): bool => $row->date->toDateString() === '2026-11-10'
                && substr((string) $row->starts_at, 0, 5) === '11:00'
        );
        $this->assertSame(1400, $calendar->whole_price_per_step_minor); // 500 + 900
        $this->assertFalse($court->auto_sync_enabled);

        // Source edits after the operation do not update the court's saved snapshot.
        $venue->schedule->intervals()->where('day_of_week', 1)->update(['starts_at' => '10:00']);
        $venue->scheduleSlotPrices()->where('day_of_week', 2)->update(['whole_price_per_step_minor' => 9999]);
        $this->assertSame('09:00', $court->schedule()->firstOrFail()->weekly_intervals[0]['starts_at']);
        $this->assertSame(1200, $court->slotPrices()->where('day_of_week', 2)
            ->where('starts_at', '11:00')->firstOrFail()->whole_price_per_step_minor);
    }

    public function test_bulk_sync_is_atomic_if_one_court_has_incompatible_price_step(): void
    {
        $venue = $this->venueWithSchedule();
        $user = User::factory()->create();
        $this->policy($venue, $user);
        $first = $venue->primaryCourt()->firstOrFail();
        $second = $venue->courts()->create([
            'name' => 'Другой зал', 'alias' => 'other-court', 'sort_order' => 20,
            'time_step_minutes' => 45, 'allows_whole' => true,
        ]);

        try {
            app(SyncVenueCourtSchedule::class)->handle(
                $venue, [$first->id, $second->id], 'period', '2026-11-09', '2026-11-15', true
            );
            $this->fail('Expected invalid court-step validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('include_prices', $exception->errors());
        }
        $this->assertFalse($first->schedule()->exists());
        $this->assertFalse($second->schedule()->exists());
        $this->assertSame(0, $first->calendarSlotPrices()->count());
    }

    public function test_sync_validates_date_range_ownership_and_price_preconditions(): void
    {
        $venue = $this->venueWithSchedule();
        $other = $this->venueWithSchedule();
        $sync = app(SyncVenueCourtSchedule::class);
        $court = $venue->primaryCourt()->firstOrFail();
        $otherCourt = $other->primaryCourt()->firstOrFail();
        foreach ([
            fn () => $sync->handle($venue, [$court->id], 'period', '2026-11-09', '2027-03-01', false),
            fn () => $sync->handle($venue, [$otherCourt->id], 'day', '2026-11-09', includePrices: false),
            fn () => $sync->handle($venue, [$court->id], 'day', '2026-11-09', includePrices: true),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Invalid synchronization must fail without side effects.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertFalse($court->schedule()->exists());
    }

    private function venueWithSchedule(): Venue
    {
        $venue = Venue::factory()->create();
        $source = $venue->schedule()->create(['timezone' => 'Europe/Moscow']);
        $source->intervals()->create([
            'day_of_week' => 1, 'starts_at' => '09:00',
            'ends_at' => '21:00', 'sort_order' => 0,
        ]);
        $source->intervals()->create([
            'day_of_week' => 2, 'starts_at' => '10:00',
            'ends_at' => '18:00', 'sort_order' => 1,
        ]);
        $venue->setRelation('schedule', $source);

        return $venue;
    }

    private function policy(Venue $venue, User $user): void
    {
        VenueBookingPolicy::query()->create([
            'venue_id' => $venue->id, 'version' => 1, 'is_enabled' => true,
            'allows_whole' => true, 'allows_halves' => true,
            'minimum_duration_minutes' => 60, 'maximum_duration_minutes' => 240,
            'time_step_minutes' => 30, 'minimum_lead_time_minutes' => 0,
            'maximum_advance_days' => 90, 'currency' => 'RUB',
            'whole_price_per_step_minor' => 500, 'half_price_per_step_minor' => 300,
            'hold_duration_minutes' => 15, 'requires_payment' => true,
            'payment_window_minutes' => 30, 'quote_validity_minutes' => 15,
            'published_by_user_id' => $user->id, 'published_at' => now(),
            'active_marker' => true,
        ]);
    }
}
