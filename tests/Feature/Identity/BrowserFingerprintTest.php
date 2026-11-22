<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserFingerprint;
use App\Support\Privacy\CookieConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BrowserFingerprintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('identity_tracking.activity_store', 'array');
        config()->set('identity_tracking.activity_write_interval_seconds', 600);
        Cache::store('array')->clear();
        $this->withCookie(CookieConsent::COOKIE_NAME, CookieConsent::guestCookie('accepted')->getValue());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function allowAccount(User $user): void
    {
        DB::table('user_cookie_consent_preferences')->insert([
            'user_id' => $user->id,
            'analytics_choice' => 'accepted',
            'notice_version' => CookieConsent::version(),
            'updated_at' => now(),
        ]);
    }

    public function test_guest_without_consent_or_after_refusal_is_not_tracked(): void
    {
        foreach (['', CookieConsent::guestCookie('rejected')->getValue()] as $choice) {
            $this->withCookie(CookieConsent::COOKIE_NAME, $choice)->get('/')
                ->assertOk()->assertCookieMissing('mskba_browser_fp');
        }
        $this->assertDatabaseCount('user_fingerprints', 0);
    }

    public function test_guest_visit_records_anonymous_browser_fingerprint(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertCookieNotExpired('mskba_browser_fp');

        $fingerprint = UserFingerprint::query()->first();

        $this->assertNotNull($fingerprint);
        $this->assertSame(1, $fingerprint->visits_count);
        $this->assertNotNull($fingerprint->fingerprint_hash);
        $this->assertNotNull($fingerprint->browser_signature_hash);
        $this->assertNotNull($fingerprint->first_seen_at);
        $this->assertNotNull($fingerprint->last_seen_at);
        $this->assertSame(0, DB::table('user_fingerprint_user')->count());
    }

    public function test_authenticated_visit_attaches_existing_browser_fingerprint_to_user(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $this->allowAccount($user);

        $response = $this
            ->get('/')
            ->assertOk();

        $fingerprintId = $response->getCookie('mskba_browser_fp')->getValue();
        $fingerprint = UserFingerprint::query()->firstOrFail();

        $this->assertSame(1, $fingerprint->visits_count);

        $this
            ->actingAs($user)
            ->withCookie('mskba_browser_fp', $fingerprintId)
            ->get('/')
            ->assertRedirect(route('account'));

        $fingerprint->refresh();

        $this->assertSame(1, $fingerprint->visits_count);
        $this->assertTrue($user->fingerprints()->whereKey($fingerprint->id)->exists());
        $this->assertSame(1, UserFingerprint::query()->count());
        $this->assertDatabaseHas('user_fingerprint_user', [
            'user_fingerprint_id' => $fingerprint->id,
            'user_id' => $user->id,
            'authentications_count' => 1,
        ]);

        $pivot = $user->fingerprints()->whereKey($fingerprint->id)->firstOrFail()->pivot;

        $this->assertNotNull($pivot->first_authenticated_at);
        $this->assertNotNull($pivot->last_authenticated_at);
    }

    public function test_login_request_attaches_existing_browser_fingerprint_to_user(): void
    {
        $user = User::factory()->create([
            'username' => 'fingerprint_user',
            'password' => 'password',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $this->allowAccount($user);

        $response = $this
            ->get('/')
            ->assertOk();

        $fingerprintId = $response->getCookie('mskba_browser_fp')->getValue();
        $fingerprint = UserFingerprint::query()->firstOrFail();

        $this->assertSame(0, DB::table('user_fingerprint_user')->count());

        $this
            ->withCookie('mskba_browser_fp', $fingerprintId)
            ->post(route('auth.login'), [
                'login' => 'fingerprint_user',
                'password' => 'password',
            ])
            ->assertRedirect('/');

        $fingerprint->refresh();

        $this->assertSame(1, $fingerprint->visits_count);
        $this->assertDatabaseHas('user_fingerprint_user', [
            'user_fingerprint_id' => $fingerprint->id,
            'user_id' => $user->id,
            'authentications_count' => 1,
        ]);
    }

    public function test_same_browser_fingerprint_can_be_linked_to_multiple_users(): void
    {
        $firstUser = User::factory()->create([
            'username' => 'fingerprint_first_user',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $secondUser = User::factory()->create([
            'username' => 'fingerprint_second_user',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $this->allowAccount($firstUser);
        $this->allowAccount($secondUser);

        $response = $this
            ->get('/')
            ->assertOk();

        $fingerprintId = $response->getCookie('mskba_browser_fp')->getValue();
        $fingerprint = UserFingerprint::query()->firstOrFail();

        $this
            ->actingAs($firstUser)
            ->withCookie('mskba_browser_fp', $fingerprintId)
            ->get('/')
            ->assertRedirect(route('account'));

        auth()->logout();

        $this
            ->actingAs($secondUser)
            ->withCookie('mskba_browser_fp', $fingerprintId)
            ->get('/')
            ->assertRedirect(route('account'));

        $fingerprint->refresh();

        $this->assertSame(1, $fingerprint->visits_count);
        $this->assertSame(1, UserFingerprint::query()->count());
        $this->assertTrue($fingerprint->users()->whereKey($firstUser->id)->exists());
        $this->assertTrue($fingerprint->users()->whereKey($secondUser->id)->exists());
        $this->assertSame(2, DB::table('user_fingerprint_user')->count());
    }

    public function test_same_user_can_have_multiple_browser_fingerprints(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $this->allowAccount($user);

        $this
            ->actingAs($user)
            ->get('/')
            ->assertRedirect(route('account'));

        $this
            ->actingAs($user)
            ->withCookie('mskba_browser_fp', '8c4fd47f-a10d-4a48-8f6e-b1222a459e78')
            ->get('/')
            ->assertRedirect(route('account'));

        $this->assertSame(2, UserFingerprint::query()->count());
        $this->assertSame(2, $user->fingerprints()->count());
    }

    public function test_activity_is_written_again_after_throttle_interval(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');

        $response = $this->get('/')->assertOk();
        $fingerprintId = $response->getCookie('mskba_browser_fp')->getValue();
        $fingerprint = UserFingerprint::query()->firstOrFail();

        $this->withCookie('mskba_browser_fp', $fingerprintId)->get('/')->assertOk();
        $this->assertSame(1, $fingerprint->fresh()->visits_count);

        Carbon::setTestNow('2026-09-10 12:10:01');

        $this->withCookie('mskba_browser_fp', $fingerprintId)->get('/')->assertOk();
        $this->assertSame(2, $fingerprint->fresh()->visits_count);
        $this->assertTrue($fingerprint->fresh()->last_seen_at->equalTo(now()));
    }
}
