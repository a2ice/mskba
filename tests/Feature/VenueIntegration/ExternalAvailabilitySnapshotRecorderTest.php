<?php

namespace Tests\Feature\VenueIntegration;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Modules\Venue\Application\Services\VenueScheduleDayOccupancy;
use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\VenueBooking\Application\UseCases\PublishVenueBookingPolicyHandler;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RequestVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueIntegration\Application\Services\ExternalAvailabilitySnapshotRecorder;
use App\Modules\VenueIntegration\Application\Services\FindSportOnDemandRefresh;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use Illuminate\Support\Facades\Cache;
use App\Modules\VenueIntegration\Application\Services\ApplyFindSportSnapshotToNativeSchedule;
use App\Modules\VenueIntegration\Application\Services\AppliedImportedCourtAvailability;
use App\Modules\VenueIntegration\Domain\Models\VenueCourtExternalScheduleImport;
use App\Modules\VenueBooking\Application\Queries\GetVenueAvailability;
use App\Modules\VenueIntegration\Application\Services\VenueIntegrationManager;
use App\Modules\VenueIntegration\Domain\Models\ExternalAvailabilitySnapshot;
use App\Modules\VenueIntegration\Domain\Models\VenueIntegrationSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class ExternalAvailabilitySnapshotRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-13 12:00:00', 'Europe/Moscow'));
    }

    private function actor(): User
    {
        $actor = User::factory()->withVerifiedContact()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $actor->createProfile([]);

        return $actor;
    }

    private function source(User $actor, Venue $venue, string $playgroundId = '4805'): VenueIntegrationSource
    {
        $manager = app(VenueIntegrationManager::class);
        $config = $manager->saveConfiguration($venue, $actor, [
            'name' => 'Тестовое расписание',
            'timezone' => 'Europe/Moscow',
            'freshness_ttl_seconds' => 900,
            'horizon_days' => 7,
            'refresh_on_visit' => false,
            'scheduled_refresh' => false,
            'mode' => 'test',
            'enabled' => false,
        ]);

        return $manager->saveSource($config, $actor, [
            'name' => 'FindSport '.$playgroundId,
            'adapter_key' => 'api',
            'external_id' => $playgroundId,
            'endpoint_url' => 'https://findsport.ru/playground/'.$playgroundId,
            'priority' => 100,
            'capabilities' => ['availability', 'pricing'],
            'permission_status' => 'testing_only',
            'permission_reference' => 'Verified offline test fixture',
            'court_ids' => [$venue->courts()->firstOrFail()->id],
            'enabled' => false,
        ]);
    }

    private function report(
        VenueIntegrationSource $source,
        string $startTime = '07:00',
        int $slotsPerDay = 34,
        string $externalCourtId = '9068',
    ): array
    {
        $days = [];
        for ($day = 0; $day < 7; $day++) {
            $date = CarbonImmutable::parse('2026-11-13', 'Europe/Moscow')->addDays($day);
            $slots = [];
            for ($i = 0; $i < $slotsPerDay; $i++) {
                $start = $date->setTimeFromTimeString($startTime)->addMinutes($i * 30);
                $slots[] = [
                    'time' => $start->format('H:i'),
                    'ends_at' => $start->addMinutes(30)->format('H:i'),
                    'status' => $day === 0 && $i === 2 ? 'booked' : 'available',
                    'price_minor' => 162500,
                ];
            }
            $days[] = [
                'date' => $date->toDateString(),
                'complete' => true,
                'slot_count' => count($slots),
                'counts' => [
                    'available' => count($slots) - ($day === 0 ? 1 : 0),
                    'booked' => $day === 0 ? 1 : 0,
                    'unavailable' => 0, 'unknown' => 0,
                ],
                'slots' => $slots,
            ];
        }

        return [
            'provider' => 'FindSport',
            'external_id' => (int) $source->external_id,
            'external_court_id' => $externalCourtId,
            'timezone' => 'Europe/Moscow',
            'step_minutes' => 30,
            'minimum_minutes' => 60,
            'price_currency' => 'RUB',
            'observed_at' => now()->toIso8601String(),
            'source_id' => $source->id,
            'venue_court_id' => $source->courtMappings()->firstOrFail()->venue_court_id,
            'days' => $days,
        ];
    }

    public function test_manual_snapshot_is_immutable_idempotent_and_keeps_provenance(): void
    {
        $actor = $this->actor();
        $venue = Venue::factory()->create();
        $source = $this->source($actor, $venue);
        $report = $this->report($source);
        $recorder = app(ExternalAvailabilitySnapshotRecorder::class);

        $snapshot = $recorder->record($source, $actor, $report);
        // PostgreSQL timestamptz cannot infer that a bare wall-clock string
        // represents UTC. Persist the offset explicitly even when the DB's
        // session timezone is Europe/Moscow.
        $this->assertSame('Y-m-d H:i:sP', $snapshot->getDateFormat());
        $this->assertSame(
            CarbonImmutable::parse($report['observed_at'])->utc()->toIso8601String(),
            $snapshot->fresh()->observed_at->utc()->toIso8601String(),
        );
        $this->assertFalse($snapshot->fresh()->isStale(now()->addMinutes(1)));
        $this->assertTrue($snapshot->complete);
        $this->assertSame($source->id, $snapshot->venue_integration_source_id);
        $this->assertSame($venue->id, $snapshot->venue_id);
        $this->assertSame(900, $snapshot->freshness_ttl_seconds);
        $this->assertSame('findsport-readonly-v1', $snapshot->parser_version);
        $this->assertSame(238, $snapshot->slots()->count());
        $this->assertSame(1, $snapshot->slots()->where('status', 'booked')->count());

        $first = $snapshot->slots()->orderBy('starts_at')->firstOrFail();
        $this->assertSame('2026-11-13 04:00', $first->starts_at->utc()->format('Y-m-d H:i'));
        $this->assertSame(162500, $first->price_minor);
        $this->assertFalse($snapshot->isStale($snapshot->observed_at->addMinutes(1)));

        $same = $recorder->record($source, $actor, $report);
        $this->assertSame($snapshot->id, $same->id);
        $this->assertDatabaseCount('external_availability_snapshots', 1);
        $this->assertDatabaseCount('external_availability_slots', 238);
        $this->assertDatabaseCount('venue_integration_audit_events', 3);

        // Saving an external observation never mutates the court's configured tariff.
        $this->assertSame($venue->courts()->firstOrFail()->whole_price_per_step_minor,
            $venue->courts()->firstOrFail()->fresh()->whole_price_per_step_minor);
    }

    public function test_partial_snapshot_cannot_delete_previous_complete_observation(): void
    {
        $actor = $this->actor();
        $source = $this->source($actor, Venue::factory()->create());
        $recorder = app(ExternalAvailabilitySnapshotRecorder::class);
        $original = $recorder->record($source, $actor, $this->report($source));

        $this->travel(2)->minutes();
        $partial = $this->report($source);
        $partial['days'][1]['complete'] = false;
        foreach ($partial['days'][1]['slots'] as &$slot) {
            $slot['status'] = 'unknown';
        }
        unset($slot);

        $saved = $recorder->record($source, $actor, $partial);
        $this->assertNotSame($original->id, $saved->id);
        $this->assertFalse($saved->complete);
        $this->assertSame(34, $saved->slots()->where('status', 'unknown')->count());
        $this->assertTrue($original->refresh()->complete);
        $this->assertSame(238, $original->slots()->count());
        $this->assertDatabaseCount('external_availability_snapshots', 2);
        $this->travel(20)->minutes();
        $this->assertTrue($original->refresh()->isStale($original->observed_at->addMinutes(20)));
    }

    public function test_invalid_report_is_rejected_without_overwriting_previous_state(): void
    {
        $actor = $this->actor();
        $source = $this->source($actor, Venue::factory()->create());
        $recorder = app(ExternalAvailabilitySnapshotRecorder::class);
        $recorder->record($source, $actor, $this->report($source));

        $tampered = $this->report($source);
        $tampered['venue_court_id'] = 999;

        try {
            $recorder->record($source, $actor, $tampered);
            $this->fail('Wrong court mapping accepted');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('не соответствуют источнику', $exception->getMessage());
        }

        $this->assertDatabaseCount('external_availability_snapshots', 1);
        $this->assertDatabaseCount('external_availability_slots', 238);
    }

    public function test_another_findsport_playground_can_save_and_apply_full_midnight_to_midnight_calendar(): void
    {
        config()->set('venue_integrations.apply_findsport_native_enabled', true);
        config()->set('venue_integrations.public_schedule_refresh_enabled', false);
        $actor = $this->actor();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $source = $this->source($actor, $venue, '5768');
        $report = $this->report($source, '00:00', 48, '10997');
        // Full occupancy on the first date, mixed occupancy on the next.
        foreach ($report['days'][0]['slots'] as &$slot) {
            $slot['status'] = 'booked';
        }
        unset($slot);
        $report['days'][0]['counts'] = [
            'available' => 0, 'booked' => 48, 'unavailable' => 0, 'unknown' => 0,
        ];

        $snapshot = app(ExternalAvailabilitySnapshotRecorder::class)
            ->record($source, $actor, $report);

        $this->assertSame('5768', (string) $snapshot->source->external_id);
        $this->assertSame('10997', $snapshot->external_court_id);
        $this->assertTrue($snapshot->complete);
        $this->assertSame(336, $snapshot->slots()->count());
        $first = $snapshot->slots()->orderBy('starts_at')->firstOrFail();
        $this->assertSame('2026-11-12 21:00', $first->starts_at->utc()->format('Y-m-d H:i'));
        $last = $snapshot->slots()->orderByDesc('starts_at')->firstOrFail();
        $this->assertSame('00:00', $last->ends_at->setTimezone('Europe/Moscow')->format('H:i'));

        $applied = app(ApplyFindSportSnapshotToNativeSchedule::class)
            ->apply($source, $snapshot, $actor);
        $court = $venue->courts()->firstOrFail()->fresh();

        $this->assertSame($snapshot->id, $applied->external_availability_snapshot_id);
        $this->assertSame('00:00', $court->schedule->weekly_intervals[0]['starts_at']);
        $this->assertSame('23:59', $court->schedule->weekly_intervals[0]['ends_at']);
        $this->assertSame('00:00', substr(
            $venue->schedule()->firstOrFail()->intervals()->firstOrFail()->starts_at, 0, 5,
        ));
        $this->assertSame(336, $court->calendarSlotPrices()->count());
        $this->assertTrue(collect($court->schedule->exceptions)->contains(
            fn (array $row): bool => $row['date'] === '2026-11-13' && ($row['is_closed'] ?? false) === true,
        ));
        $this->assertDatabaseCount('venue_bookings', 0);
        $this->assertSame(100, app(VenueScheduleDayOccupancy::class)->forDays(
            $venue, $court, [['date' => '2026-11-13', 'intervals' => []]], 'Europe/Moscow',
        )['2026-11-13']);

        // With the refresh switch off this endpoint uses only the applied
        // snapshot and shows all 48 cells, including the booked night.
        $this->getJson(route('venues.schedule.day', [
            'venue' => $venue->id, 'date' => '2026-11-13', 'venue_court_id' => $court->id,
        ]))->assertOk()
            ->assertJsonCount(48, 'slots')
            ->assertJsonPath('slots.0.starts_at', '00:00')
            ->assertJsonPath('slots.0.status', 'booked')
            ->assertJsonPath('slots.47.starts_at', '23:30')
            ->assertJsonPath('slots.47.status', 'booked');

        // The following day's final FindSport cell is available up to actual
        // midnight. Imported native hours use 23:59 as an HTML-time sentinel.
        // That sentinel must not discard the 23:30–00:00 cell.
        $this->getJson(route('venues.schedule.day', [
            'venue' => $venue->id, 'date' => '2026-11-14', 'venue_court_id' => $court->id,
        ]))->assertOk()
            ->assertJsonCount(48, 'slots')
            ->assertJsonPath('slots.46.status', 'available')
            ->assertJsonPath('slots.47.starts_at', '23:30')
            ->assertJsonPath('slots.47.ends_at', '24:00')
            ->assertJsonPath('slots.47.status', 'available');

        $this->getJson(route('venues.schedule.day', [
            'venue' => $venue->id, 'venue_court_id' => $court->id,
            'date' => '2026-11-14', 'search_until' => '2026-11-14',
            'search_from_time' => '23:00', 'search_to_time' => '23:59',
        ]))->assertOk()->assertJsonCount(1, 'days')->assertJsonCount(2, 'days.0.slots')
            ->assertJsonPath('days.0.slots.1.starts_at', '23:30')
            ->assertJsonPath('days.0.slots.1.ends_at', '24:00')
            ->assertJsonMissingPath('days.0.slots.1.booking_group');
        $this->getJson(route('venues.schedule.day', [
            'venue' => $venue->id, 'venue_court_id' => $court->id,
            'date' => '2026-11-13', 'search_until' => '2026-11-14',
            'search_from_time' => '23:30', 'search_to_time' => '00:30',
        ]))->assertOk()->assertJsonCount(2, 'days')
            ->assertJsonCount(1, 'days.0.slots')->assertJsonCount(1, 'days.1.slots');

        $again = app(ApplyFindSportSnapshotToNativeSchedule::class)
            ->apply($source, $snapshot, $actor);
        $this->assertSame($applied->id, $again->id);
        $this->assertDatabaseCount('external_availability_snapshots', 1);
        $this->assertDatabaseCount('external_availability_slots', 336);
    }

    public function test_findsport_rental_can_end_exactly_at_midnight_and_create_a_request(): void
    {
        config()->set('venue_integrations.apply_findsport_native_enabled', true);
        config()->set('venue_integrations.public_schedule_refresh_enabled', false);
        config()->set('features.venue_rental.rental_flow', true);
        $actor = $this->actor();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $source = $this->source($actor, $venue);
        $snapshot = app(ExternalAvailabilitySnapshotRecorder::class)->record(
            $source, $actor, $this->report($source),
        );
        app(ApplyFindSportSnapshotToNativeSchedule::class)->apply($source, $snapshot, $actor);
        $court = $venue->courts()->firstOrFail()->fresh();
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $actor, [
            'is_enabled' => true,
            'allows_whole' => true,
            'allows_halves' => false,
            'minimum_duration_minutes' => 60,
            'maximum_duration_minutes' => 180,
            'time_step_minutes' => 30,
            'minimum_lead_time_minutes' => 0,
            'maximum_advance_days' => 90,
            'currency' => 'RUB',
            'whole_price_per_step_minor' => 50000,
            'half_price_per_step_minor' => null,
            'hold_duration_minutes' => 30,
            'requires_payment' => true,
            'payment_window_minutes' => 30,
            'quote_validity_minutes' => 15,
            'cancellation_before_minutes' => 1440,
        ]);
        // Anchor the test clock to the immutable observation; never bypass
        // the real 15-minute freshness guard for the imported schedule.
        $this->travelTo($snapshot->observed_at->addMinute());
        $this->assertFalse($snapshot->isStale());
        $start = CarbonImmutable::parse('2026-11-14 23:00:00', 'Europe/Moscow');
        $end = $start->addHour();

        // Creation of ordinary events still uses the existing no-cross-day rule.
        try {
            app(VenueEventAvailability::class)->assertAvailable($venue, $start, $end, court: $court);
            $this->fail('An event unexpectedly accepted a next-day end.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('мероприятие должно завершаться', $exception->getMessage());
        }

        $quotes = app(QuoteVenueBookingHandler::class);
        $quote = $quotes->handle($venue, $start, 60, VenueBookingScopeEnum::WHOLE, $actor, $court);
        $this->assertSame('2026-11-15 00:00', $quote->endsAt->setTimezone('Europe/Moscow')->format('Y-m-d H:i'));
        $this->getJson(route('venues.rental.quote.status', [
            'venue' => $venue->id, 'quote_id' => $quote->publicId,
        ]))->assertOk()->assertJsonPath('available', true);
        $booking = app(RequestVenueBookingHandler::class)->handle(
            app(CurrentActorResolver::class)->resolve($actor, null), $quote->publicId,
        );
        $this->assertSame('2026-11-15 00:00', $booking->ends_at->setTimezone('Europe/Moscow')->format('Y-m-d H:i'));
        $this->assertDatabaseCount('venue_bookings', 1);

        // Thirty minutes cannot bypass the imported one-hour minimum.
        try {
            $quotes->handle($venue, $start->addMinutes(30), 30, VenueBookingScopeEnum::WHOLE, $actor, $court);
            $this->fail('Minimum duration must remain enforced.');
        } catch (VenueBookingPolicyException $exception) {
            $this->assertStringContainsString('Длительность не соответствует', $exception->getMessage());
        }
        // 23:30 + 60 really runs into the next day and remains forbidden.
        try {
            $quotes->handle($venue, $start->addMinutes(30), 60, VenueBookingScopeEnum::WHOLE, $actor, $court);
            $this->fail('An overnight rental unexpectedly passed the midnight guard.');
        } catch (VenueBookingPolicyException $exception) {
            $this->assertStringContainsString('не позднее 00:00', $exception->getMessage());
        }
    }

    public function test_full_day_snapshot_rejects_missing_interval_and_never_applies_it(): void
    {
        config()->set('venue_integrations.apply_findsport_native_enabled', true);
        $actor = $this->actor();
        $venue = Venue::factory()->create();
        $source = $this->source($actor, $venue, '5768');
        $report = $this->report($source, '00:00', 48, '10997');
        array_splice($report['days'][2]['slots'], 20, 1);
        $report['days'][2]['slot_count'] = 47;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('непоследовательная');
        app(ExternalAvailabilitySnapshotRecorder::class)->record($source, $actor, $report);
    }

    public function test_native_import_overwrites_hours_prices_and_occupation_without_fake_bookings(): void
    {
        config()->set('venue_integrations.apply_findsport_native_enabled', true);
        $actor = $this->actor();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $source = $this->source($actor, $venue);
        $snapshot = app(ExternalAvailabilitySnapshotRecorder::class)->record(
            $source, $actor, $this->report($source),
        );

        $import = app(ApplyFindSportSnapshotToNativeSchedule::class)->apply($source, $snapshot, $actor);
        $court = $venue->courts()->firstOrFail()->fresh();

        $this->assertSame($snapshot->id, $import->external_availability_snapshot_id);
        $this->assertSame($court->id, $import->venue_court_id);
        $this->assertSame(30, $court->time_step_minutes);
        $this->assertSame(60, $court->minimum_duration_minutes);
        $this->assertSame(7, $venue->schedule()->firstOrFail()->intervals()->count());
        $this->assertCount(7, $court->schedule->weekly_intervals);
        $this->assertSame('07:00', $court->schedule->weekly_intervals[0]['starts_at']);
        $this->assertSame('23:59', $court->schedule->weekly_intervals[0]['ends_at']);
        $this->assertSame(238, $court->calendarSlotPrices()->count());
        // A 07:00–midnight FindSport schedule must also expose its last cell.
        $this->getJson(route('venues.schedule.day', [
            'venue' => $venue->id, 'date' => '2026-11-14', 'venue_court_id' => $court->id,
        ]))->assertOk()
            ->assertJsonCount(34, 'slots')
            ->assertJsonPath('slots.33.starts_at', '23:30')
            ->assertJsonPath('slots.33.ends_at', '24:00')
            ->assertJsonPath('slots.33.status', 'available');
        $this->assertSame(162500, $court->calendarSlotPrices()
            ->whereDate('date', '2026-11-13')->where('starts_at', '07:00:00')
            ->value('whole_price_per_step_minor'));
        $exceptions = collect($court->schedule->exceptions)
            ->where('date', '2026-11-13')->values()->all();
        $this->assertCount(2, $exceptions);
        $this->assertSame('08:00', $exceptions[0]['ends_at']);
        $this->assertSame('08:30', $exceptions[1]['starts_at']);
        $this->assertDatabaseCount('venue_bookings', 0);

        // 1 booked of 34 known 30-minute slots = 3%. Full free is 0%,
        // while an out-of-coverage day must not masquerade as free.
        $percentages = app(VenueScheduleDayOccupancy::class)->forDays(
            $venue, $court, [
                ['date' => '2026-11-13', 'intervals' => []],
                ['date' => '2026-11-14', 'intervals' => []],
                ['date' => '2026-11-20', 'intervals' => []],
            ], 'Europe/Moscow',
        );
        $this->assertSame(3, $percentages['2026-11-13']);
        $this->assertSame(0, $percentages['2026-11-14']);
        $this->assertNull($percentages['2026-11-20']);

        $projection = app(GetVenueAvailability::class)->handle(
            $venue, CarbonImmutable::parse('2026-11-13 07:00', 'Europe/Moscow')->utc(),
            CarbonImmutable::parse('2026-11-13 10:00', 'Europe/Moscow')->utc(), $court,
        );
        $this->assertSame($snapshot->id, $projection['external_snapshot_id']);
        $this->assertCount(1, $projection['busy']);
        $this->assertSame('findsport', $projection['busy'][0]['source']);

        $guard = app(AppliedImportedCourtAvailability::class);
        $guard->assertAvailable($court,
            CarbonImmutable::parse('2026-11-13 09:00', 'Europe/Moscow'),
            CarbonImmutable::parse('2026-11-13 10:00', 'Europe/Moscow'),
            $snapshot->observed_at->addMinutes(1));
        try {
            $guard->assertAvailable($court,
                CarbonImmutable::parse('2026-11-13 08:00', 'Europe/Moscow'),
                CarbonImmutable::parse('2026-11-13 09:00', 'Europe/Moscow'),
                $snapshot->observed_at->addMinutes(1));
            $this->fail('Occupied interval was treated as free.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('занято или недоступно', $exception->getMessage());
        }

        $same = app(ApplyFindSportSnapshotToNativeSchedule::class)->apply($source, $snapshot, $actor);
        $this->assertSame($import->id, $same->id);
        $this->assertDatabaseCount('venue_court_external_schedule_imports', 1);
        $this->assertSame(238, $court->calendarSlotPrices()->count());
    }

    public function test_event_availability_distinguishes_imported_occupancy_from_opening_hours(): void
    {
        config()->set('venue_integrations.apply_findsport_native_enabled', true);
        config()->set('venue_integrations.public_schedule_refresh_enabled', false);
        $actor = $this->actor();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $source = $this->source($actor, $venue);
        $report = $this->report($source);
        $report['days'][1]['slots'][2]['status'] = 'booked';
        $report['days'][1]['slots'][3]['status'] = 'booked';
        $report['days'][1]['counts']['available'] -= 2;
        $report['days'][1]['counts']['booked'] += 2;
        foreach ($report['days'][2]['slots'] as &$slot) {
            $slot['status'] = 'booked';
        }
        unset($slot);
        $report['days'][2]['counts']['booked'] = $report['days'][2]['slot_count'];
        $report['days'][2]['counts']['available'] = 0;
        $report['days'][3]['slots'][2]['status'] = 'unavailable';
        $report['days'][3]['counts']['unavailable'] = 1;
        $report['days'][3]['counts']['available']--;
        $snapshot = app(ExternalAvailabilitySnapshotRecorder::class)->record($source, $actor, $report);
        app(ApplyFindSportSnapshotToNativeSchedule::class)->apply($source, $snapshot, $actor);
        $this->travelTo($snapshot->observed_at->addMinute());

        $assertInterval = function (string $start, int $minutes, ?string $error = null) use ($venue): void {
            $freshVenue = $venue->fresh();
            $court = $freshVenue->courts()->firstOrFail();
            $startsAt = CarbonImmutable::parse($start, 'Europe/Moscow');
            try {
                app(VenueEventAvailability::class)->assertAvailable(
                    $freshVenue, $startsAt, $startsAt->addMinutes($minutes), court: $court,
                );
                $this->assertNull($error, 'Expected availability rejection.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame($error, $exception->getMessage());
            }
        };

        $assertInterval('2026-11-14 08:00', 60, 'Выбранное время уже занято.');
        $assertInterval('2026-11-14 08:30', 60, 'Выбранное время уже занято частично.');
        $assertInterval('2026-11-15 10:00', 60, 'Выбранное время уже занято.');
        $assertInterval('2026-11-16 08:00', 60, 'Выбранное время недоступно по расписанию FindSport.');
        $assertInterval('2026-11-14 06:00', 60, 'Выбранное время не входит в часы работы площадки.');
        $assertInterval('2026-11-14 09:00', 60);

        // An explicit parent closure still takes precedence.
        $venue->schedule()->firstOrFail()->exceptions()->create([
            'date' => '2026-11-15', 'is_closed' => true,
        ]);
        $assertInterval('2026-11-15 10:00', 60, 'В выбранную дату площадка закрыта.');

        $this->travelTo($snapshot->observed_at->addHours(2));
        $assertInterval('2026-11-14 08:00', 60, 'Требуется обновление расписания');
    }

    public function test_day_occupancy_bar_becomes_fully_red_when_all_slots_are_booked(): void
    {
        config()->set('venue_integrations.apply_findsport_native_enabled', true);
        $actor = $this->actor();
        $venue = Venue::factory()->create();
        $source = $this->source($actor, $venue);
        $report = $this->report($source);
        foreach ($report['days'][0]['slots'] as &$slot) {
            $slot['status'] = 'booked';
        }
        unset($slot);

        $snapshot = app(ExternalAvailabilitySnapshotRecorder::class)
            ->record($source, $actor, $report);
        app(ApplyFindSportSnapshotToNativeSchedule::class)->apply($source, $snapshot, $actor);

        $summary = app(VenueScheduleDayOccupancy::class)->forDays(
            $venue, $venue->courts()->firstOrFail(),
            [['date' => '2026-11-13', 'intervals' => []]], 'Europe/Moscow',
        );

        $this->assertSame(100, $summary['2026-11-13']);
    }

    public function test_native_import_rejects_partial_and_does_not_change_court(): void
    {
        config()->set('venue_integrations.apply_findsport_native_enabled', true);
        $actor = $this->actor();
        $venue = Venue::factory()->create();
        $source = $this->source($actor, $venue);
        $report = $this->report($source);
        $report['days'][1]['complete'] = false;
        foreach ($report['days'][1]['slots'] as &$slot) {
            $slot['status'] = 'unknown';
        }
        unset($slot);
        $snapshot = app(ExternalAvailabilitySnapshotRecorder::class)->record($source, $actor, $report);

        try {
            app(ApplyFindSportSnapshotToNativeSchedule::class)->apply($source, $snapshot, $actor);
            $this->fail('Incomplete snapshot was applied');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('неполный', $exception->getMessage());
        }

        $this->assertDatabaseCount('venue_court_external_schedule_imports', 0);
        $this->assertDatabaseCount('venue_court_calendar_slot_prices', 0);
    }

    public function test_native_import_is_disabled_outside_staging(): void
    {
        config()->set('venue_integrations.apply_findsport_native_enabled', false);
        $actor = $this->actor();
        $source = $this->source($actor, Venue::factory()->create());
        $snapshot = app(ExternalAvailabilitySnapshotRecorder::class)->record(
            $source, $actor, $this->report($source),
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('отключено конфигурацией сервера');
        app(ApplyFindSportSnapshotToNativeSchedule::class)->apply($source, $snapshot, $actor);
    }

    public function test_public_day_modal_reads_applied_native_schedule_and_reuses_recent_snapshot(): void
    {
        config()->set('venue_integrations.apply_findsport_native_enabled', true);
        // The HTTP response must not issue an external request in tests.
        config()->set('venue_integrations.public_schedule_refresh_enabled', false);
        config()->set('venue_integrations.findsport_preview_enabled', true);
        config()->set('features.venue_rental.rental_flow', false);

        $actor = $this->actor();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $source = $this->source($actor, $venue);
        $snapshot = app(ExternalAvailabilitySnapshotRecorder::class)->record(
            $source, $actor, $this->report($source),
        );
        $court = $venue->courts()->firstOrFail();
        app(ApplyFindSportSnapshotToNativeSchedule::class)->apply($source, $snapshot, $actor);

        $path = route('venues.schedule.day', ['venue' => $venue->id,
            'date' => '2026-11-13', 'venue_court_id' => $court->id]);

        $this->getJson($path)->assertOk()
            ->assertJsonPath('date', '2026-11-13')
            ->assertJsonPath('step_minutes', 30)
            ->assertJsonPath('minimum_minutes', 60)
            ->assertJsonPath('refresh_interval_seconds', 300)
            ->assertJsonPath('refresh_state', 'disabled')
            ->assertJsonPath('booking_enabled', false)
            ->assertJsonPath('slots.0.starts_at', '07:00')
            ->assertJsonPath('slots.0.status', 'available')
            ->assertJsonPath('slots.0.price_minor', 162500)
            ->assertJsonPath('slots.2.status', 'booked')
            ->assertJsonPath('slots.2.selectable', false)
            ->assertJsonCount(34, 'slots');

        $this->getJson($path)->assertOk()
            ->assertJsonPath('refresh_state', 'disabled');
        $this->assertDatabaseCount('external_availability_snapshots', 1);
        $this->assertDatabaseCount('venue_court_external_schedule_imports', 1);
        $this->assertDatabaseCount('venue_bookings', 0);
    }

    public function test_public_imported_prices_use_native_manual_overrides_and_venue_minimum(): void
    {
        config()->set('venue_integrations.apply_findsport_native_enabled', true);
        config()->set('venue_integrations.public_schedule_refresh_enabled', false);
        config()->set('venue_integrations.findsport_preview_enabled', true);
        config()->set('features.venue_rental.rental_flow', false);

        $actor = $this->actor();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $source = $this->source($actor, $venue);
        $snapshot = app(ExternalAvailabilitySnapshotRecorder::class)->record(
            $source, $actor, $this->report($source),
        );
        $court = $venue->courts()->firstOrFail();
        app(ApplyFindSportSnapshotToNativeSchedule::class)->apply($source, $snapshot, $actor);
        VenueBookingPolicy::query()->create([
            'venue_id' => $venue->id, 'version' => 1, 'is_enabled' => true,
            'allows_whole' => true, 'allows_halves' => false,
            'minimum_duration_minutes' => 60, 'maximum_duration_minutes' => 180,
            'time_step_minutes' => 30, 'minimum_lead_time_minutes' => 0,
            'maximum_advance_days' => 90, 'currency' => 'RUB',
            'whole_price_per_step_minor' => 200000, 'half_price_per_step_minor' => null,
            'hold_duration_minutes' => 30, 'requires_payment' => true,
            'payment_window_minutes' => 30, 'quote_validity_minutes' => 15,
            'published_by_user_id' => $actor->id, 'published_at' => now(),
            'active_marker' => true,
        ]);

        $path = route('venues.schedule.day', [
            'venue' => $venue->id, 'date' => '2026-11-13', 'venue_court_id' => $court->id,
        ]);
        // FindSport reports 1625 ₽, but the venue's minimum is 2000 ₽.
        $this->getJson($path)->assertOk()->assertJsonMissingPath('slots.0.pricing_breakdown')->assertJsonPath('slots.0.price_minor', 200000);
        $court->calendarSlotPrices()->whereDate('date', '2026-11-13')
            ->where('starts_at', '07:00:00')
            ->update(['whole_price_per_step_minor' => 250000]);

        // Local manual price wins over the previously imported tariff.
        $this->getJson($path)->assertOk()->assertJsonMissingPath('slots.0.pricing_breakdown')->assertJsonPath('slots.0.price_minor', 250000);
        $this->assertSame(162500, (int) $snapshot->slots()->orderBy('starts_at')
            ->firstOrFail()->price_minor);

    }

    public function test_public_day_refresh_uses_one_shared_lock_after_five_minutes(): void
    {
        config()->set('venue_integrations.apply_findsport_native_enabled', true);
        config()->set('venue_integrations.public_schedule_refresh_enabled', true);
        config()->set('venue_integrations.findsport_preview_enabled', true);

        $actor = $this->actor();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $source = $this->source($actor, $venue);
        $snapshot = app(ExternalAvailabilitySnapshotRecorder::class)->record(
            $source, $actor, $this->report($source),
        );
        $court = $venue->courts()->firstOrFail();
        app(ApplyFindSportSnapshotToNativeSchedule::class)->apply($source, $snapshot, $actor);

        $this->assertSame('cached', app(FindSportOnDemandRefresh::class)
            ->forCourt($court, $snapshot->observed_at->addMinute())['state']);
        $lock = Cache::lock("venue-integration:findsport:refresh-lock:{$source->id}", 45);
        $this->assertTrue($lock->get());
        try {
            $result = app(FindSportOnDemandRefresh::class)
                ->forCourt($court, $snapshot->observed_at->addMinutes(6));
            $this->assertSame('updating', $result['state']);
        } finally {
            $lock->release();
        }
        $this->assertDatabaseCount('external_availability_snapshots', 1);
    }

    public function test_public_refresh_can_save_and_apply_without_admin_actor(): void
    {
        config()->set('venue_integrations.apply_findsport_native_enabled', true);
        config()->set('venue_integrations.public_schedule_refresh_enabled', true);
        $actor = $this->actor();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $source = $this->source($actor, $venue);

        $snapshot = app(ExternalAvailabilitySnapshotRecorder::class)
            ->recordFromPublicSchedule($source, $this->report($source));

        $this->assertNull($snapshot->created_by_user_id);
        $applied = app(ApplyFindSportSnapshotToNativeSchedule::class)
            ->applyFromPublicSchedule($source, $snapshot);

        $this->assertSame($snapshot->id, $applied->external_availability_snapshot_id);
        $this->assertNull($applied->applied_by_user_id);
        $this->assertDatabaseCount('venue_court_external_schedule_imports', 1);
        $this->assertDatabaseCount('venue_bookings', 0);
    }

    public function test_production_requires_approved_source_for_native_import_and_public_refresh(): void
    {
        config()->set('venue_integrations.apply_findsport_native_enabled', true);
        config()->set('venue_integrations.public_schedule_refresh_enabled', true);
        config()->set('venue_integrations.findsport_preview_enabled', true);

        $actor = $this->actor();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $source = $this->source($actor, $venue);
        $snapshot = app(ExternalAvailabilitySnapshotRecorder::class)->record(
            $source, $actor, $this->report($source),
        );
        $court = $venue->courts()->firstOrFail();
        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'production');

        try {
            $this->assertSame('not_configured', app(FindSportOnDemandRefresh::class)
                ->forCourt($court, $snapshot->observed_at->addMinute())['state']);
            try {
                app(ApplyFindSportSnapshotToNativeSchedule::class)->apply($source, $snapshot, $actor);
                $this->fail('A testing-only source must never modify production schedules.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('проверенный', $exception->getMessage());
            }

            $source->update(['permission_status' => 'mskba_allowed']);
            $applied = app(ApplyFindSportSnapshotToNativeSchedule::class)->apply(
                $source, $snapshot, $actor,
            );
            $this->assertSame($snapshot->id, $applied->external_availability_snapshot_id);
            $this->assertSame('cached', app(FindSportOnDemandRefresh::class)
                ->forCourt($court, $snapshot->observed_at->addMinute())['state']);

            config()->set('venue_integrations.public_schedule_refresh_enabled', false);
            $this->assertSame('disabled', app(FindSportOnDemandRefresh::class)
                ->forCourt($court, $snapshot->observed_at->addMinute())['state']);
            $this->assertDatabaseCount('venue_bookings', 0);
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
        }
    }

    public function test_public_day_endpoint_rejects_another_court_and_out_of_range_date(): void
    {
        config()->set('venue_integrations.apply_findsport_native_enabled', true);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $another = Venue::factory()->create();
        $this->getJson(route('venues.schedule.day', [
            'venue' => $venue->id, 'date' => '2026-11-13',
            'venue_court_id' => $another->courts()->firstOrFail()->id,
        ]))->assertUnprocessable();

        $this->getJson(route('venues.schedule.day', [
            'venue' => $venue->id, 'date' => '2026-12-30',
        ]))->assertUnprocessable();
    }

    public function test_different_sources_for_same_court_are_not_merged(): void
    {
        $actor = $this->actor();
        $venue = Venue::factory()->create();
        $sourceA = $this->source($actor, $venue);
        $manager = app(VenueIntegrationManager::class);
        $sourceB = $manager->saveSource($sourceA->integration, $actor, [
            'name' => 'Second test source',
            'adapter_key' => 'api',
            'external_id' => '4805',
            'endpoint_url' => 'https://findsport.ru/playground/4805',
            'priority' => 200,
            'capabilities' => ['availability', 'pricing'],
            'permission_status' => 'testing_only',
            'permission_reference' => 'Verified offline fixture',
            'court_ids' => [$venue->courts()->firstOrFail()->id],
            'enabled' => false,
        ]);

        $recorder = app(ExternalAvailabilitySnapshotRecorder::class);
        $recorder->record($sourceA, $actor, $this->report($sourceA));
        $recorder->record($sourceB, $actor, $this->report($sourceB));

        $this->assertDatabaseCount('external_availability_snapshots', 2);
        $this->assertSame(2, ExternalAvailabilitySnapshot::query()
            ->where('venue_court_id', $venue->courts()->firstOrFail()->id)->distinct()
            ->count('venue_integration_source_id'));
    }
}
