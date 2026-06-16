<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BrowserFingerprintTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertOk();

        $fingerprint->refresh();

        $this->assertSame(2, $fingerprint->visits_count);
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

        $this->assertSame(2, $fingerprint->visits_count);
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

        $response = $this
            ->get('/')
            ->assertOk();

        $fingerprintId = $response->getCookie('mskba_browser_fp')->getValue();
        $fingerprint = UserFingerprint::query()->firstOrFail();

        $this
            ->actingAs($firstUser)
            ->withCookie('mskba_browser_fp', $fingerprintId)
            ->get('/')
            ->assertOk();

        auth()->logout();

        $this
            ->actingAs($secondUser)
            ->withCookie('mskba_browser_fp', $fingerprintId)
            ->get('/')
            ->assertOk();

        $fingerprint->refresh();

        $this->assertSame(3, $fingerprint->visits_count);
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

        $this
            ->actingAs($user)
            ->get('/')
            ->assertOk();

        $this
            ->actingAs($user)
            ->withCookie('mskba_browser_fp', '8c4fd47f-a10d-4a48-8f6e-b1222a459e78')
            ->get('/')
            ->assertOk();

        $this->assertSame(2, UserFingerprint::query()->count());
        $this->assertSame(2, $user->fingerprints()->count());
    }
}
