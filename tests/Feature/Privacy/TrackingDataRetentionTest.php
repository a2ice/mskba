<?php

namespace Tests\Feature\Privacy;

use App\Modules\Analytics\Domain\Models\GameLiveViewSession;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\UserFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TrackingDataRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-10 12:00:00');
        config()->set('identity_tracking.fingerprint_retention_days', 90);
        config()->set('game_live.history_retention_days', 90);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_prune_command_removes_only_expired_tracking_data_in_bounded_batches(): void
    {
        $expiredFingerprint = $this->fingerprint('expired', now()->subDays(91));
        $freshFingerprint = $this->fingerprint('fresh', now()->subDays(89));
        $actor = Actor::query()->create([
            'actor_key' => 'guest:retention-test',
            'type' => ActorTypeEnum::GUEST,
            'user_fingerprint_id' => $expiredFingerprint->id,
        ]);
        $game = $this->game();

        $this->liveSession($game, $expiredFingerprint, 'expired-session', now()->subDays(91));
        $this->liveSession($game, $freshFingerprint, 'fresh-session', now()->subDays(89));

        $this->artisan('privacy:prune-tracking', ['--batch' => 1, '--max-batches' => 1])
            ->expectsOutput('Удалено live-сессий: 1; fingerprint: 1.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('user_fingerprints', ['id' => $expiredFingerprint->id]);
        $this->assertDatabaseHas('user_fingerprints', ['id' => $freshFingerprint->id]);
        $this->assertDatabaseMissing('game_live_view_sessions', ['viewer_key_hash' => hash('sha256', 'expired-session')]);
        $this->assertDatabaseHas('game_live_view_sessions', ['viewer_key_hash' => hash('sha256', 'fresh-session')]);
        $this->assertNull($actor->fresh()->user_fingerprint_id);
    }

    public function test_diagnostic_command_reports_only_aggregate_counts(): void
    {
        $this->fingerprint('expired', now()->subDays(91));
        $this->fingerprint('fresh', now());

        $this->assertSame(0, Artisan::call('privacy:tracking-diagnose', ['--json' => true]));

        $output = Artisan::output();
        $this->assertStringContainsString('"fingerprints_total": 2', $output);
        $this->assertStringContainsString('"fingerprints_created_24h": 1', $output);
        $this->assertStringContainsString('"fingerprints_expired": 1', $output);
    }

    private function fingerprint(string $seed, Carbon $lastSeenAt): UserFingerprint
    {
        $fingerprint = UserFingerprint::query()->create([
            'fingerprint_hash' => hash('sha256', $seed),
            'browser_signature_hash' => hash('sha256', $seed.'-browser'),
            'ip_hash' => hash('sha256', $seed.'-ip'),
            'visits_count' => 1,
            'first_seen_at' => $lastSeenAt,
            'last_seen_at' => $lastSeenAt,
        ]);

        DB::table('user_fingerprints')->where('id', $fingerprint->id)->update([
            'created_at' => $lastSeenAt,
            'updated_at' => $lastSeenAt,
        ]);

        return $fingerprint;
    }

    private function game(): Game
    {
        $event = Event::factory()->create(['type' => EventTypeEnum::GAME_TRAINING]);
        $actor = Actor::query()->create([
            'actor_key' => 'system:retention-test',
            'type' => ActorTypeEnum::SYSTEM,
        ]);

        return Game::query()->create([
            'event_id' => $event->id,
            'created_by_actor_id' => $actor->id,
            'title' => 'Retention test',
            'status' => GameStatusEnum::SCHEDULED,
            'side_a_size' => 3,
            'side_b_size' => 3,
        ]);
    }

    private function liveSession(Game $game, UserFingerprint $fingerprint, string $viewer, Carbon $lastSeenAt): void
    {
        $session = GameLiveViewSession::query()->create([
            'game_id' => $game->id,
            'user_fingerprint_id' => $fingerprint->id,
            'viewer_key_hash' => hash('sha256', $viewer),
            'started_at' => $lastSeenAt,
            'last_seen_at' => $lastSeenAt,
        ]);

        $session->forceFill([
            'created_at' => $lastSeenAt,
            'updated_at' => $lastSeenAt,
        ])->saveQuietly();
    }
}
