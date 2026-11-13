<?php

namespace Tests\Feature\VenueIntegration;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueIntegration\Application\Services\ExternalAvailabilitySnapshotRecorder;
use App\Modules\VenueIntegration\Application\Services\FindSportPublicCourtSchedule;
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
        $actor = User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $actor->createProfile([]);

        return $actor;
    }

    private function source(User $actor, Venue $venue): VenueIntegrationSource
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
            'name' => 'FindSport Basketpoint',
            'adapter_key' => 'api',
            'external_id' => '4805',
            'endpoint_url' => 'https://findsport.ru/playground/4805',
            'priority' => 100,
            'capabilities' => ['availability', 'pricing'],
            'permission_status' => 'testing_only',
            'permission_reference' => 'Verified offline test fixture',
            'court_ids' => [$venue->courts()->firstOrFail()->id],
            'enabled' => false,
        ]);
    }

    private function report(VenueIntegrationSource $source): array
    {
        $days = [];
        for ($day = 0; $day < 7; $day++) {
            $date = CarbonImmutable::parse('2026-11-13', 'Europe/Moscow')->addDays($day);
            $slots = [];
            for ($i = 0; $i < 34; $i++) {
                $start = $date->setTime(7, 0)->addMinutes($i * 30);
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
            'external_id' => 4805,
            'external_court_id' => '9068',
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

    public function test_public_court_projection_reads_only_saved_findsport_slots_on_staging(): void
    {
        config()->set('venue_integrations.findsport_public_snapshot_enabled', true);
        $actor = $this->actor();
        $venue = Venue::factory()->create();
        $source = $this->source($actor, $venue);
        $snapshot = app(ExternalAvailabilitySnapshotRecorder::class)->record(
            $source, $actor, $this->report($source),
        );

        $court = $venue->courts()->firstOrFail();
        $projected = app(FindSportPublicCourtSchedule::class)->forCourt(
            $court, CarbonImmutable::parse('2026-11-13', 'Europe/Moscow')->startOfDay(),
        );
        $this->assertNotNull($projected);
        $this->assertSame($snapshot->id, $projected['snapshot_id']);
        $this->assertSame($source->id, $projected['source_id']);
        $this->assertCount(7, $projected['days']);
        $this->assertCount(34, $projected['days']['2026-11-13']);
        $this->assertSame('07:00', $projected['days']['2026-11-13'][0]['starts_at']);
        $this->assertSame('available', $projected['days']['2026-11-13'][0]['status']);
        $this->assertSame(162500, $projected['days']['2026-11-13'][0]['price_minor']);
        $this->assertSame('booked', $projected['days']['2026-11-13'][2]['status']);

        // Reading the public projection must not mutate booking or tariff state.
        $this->assertDatabaseCount('external_availability_snapshots', 1);
        $this->assertDatabaseCount('venue_bookings', 0);
    }

    public function test_public_findsport_projection_is_disabled_without_staging_feature_flag(): void
    {
        config()->set('venue_integrations.findsport_public_snapshot_enabled', false);
        $actor = $this->actor();
        $venue = Venue::factory()->create();
        $source = $this->source($actor, $venue);
        app(ExternalAvailabilitySnapshotRecorder::class)->record(
            $source, $actor, $this->report($source),
        );

        $projection = app(FindSportPublicCourtSchedule::class)->forCourt(
            $venue->courts()->firstOrFail(),
            CarbonImmutable::parse('2026-11-13', 'Europe/Moscow')->startOfDay(),
        );
        $this->assertNull($projection);
    }

    public function test_public_findsport_projection_does_not_leak_to_other_venue_courts(): void
    {
        config()->set('venue_integrations.findsport_public_snapshot_enabled', true);
        $actor = $this->actor();
        $venue = Venue::factory()->create();
        $source = $this->source($actor, $venue);
        app(ExternalAvailabilitySnapshotRecorder::class)->record(
            $source, $actor, $this->report($source),
        );
        $another = Venue::factory()->create();

        $this->assertNull(app(FindSportPublicCourtSchedule::class)->forCourt(
            $another->courts()->firstOrFail(),
            CarbonImmutable::parse('2026-11-13', 'Europe/Moscow')->startOfDay(),
        ));
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
