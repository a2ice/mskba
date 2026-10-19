<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class MobileAuthApiTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $username = 'mobile_tester', string $password = 'Secret#pass123'): User
    {
        $user = User::factory()->create([
            'username' => $username,
            'password' => $password,
            'status' => UserStatusEnum::UNCONFIRMED->value,
        ]);
        $user->createProfile(['first_name' => 'Тест', 'last_name' => 'Игрок']);

        return $user;
    }

    private function login(string $login = 'mobile_tester', string $password = 'Secret#pass123'): array
    {
        return $this->postJson(route('api.v1.auth.login'), [
            'login' => $login,
            'password' => $password,
            'device_name' => 'test-ios',
        ])->assertOk()->json();
    }

    public function test_login_issues_a_scoped_expiring_token_and_returns_own_profile(): void
    {
        $user = $this->account();
        $result = $this->login();

        $this->assertSame('Bearer', $result['token_type']);
        $this->assertNotEmpty($result['access_token']);
        $this->assertNotEmpty($result['expires_at']);
        $this->assertFalse($result['requires_password_change']);
        $this->assertSame($user->id, $result['user']['id']);
        $this->assertSame('Тест', $result['user']['profile']['first_name']);

        $stored = $user->tokens()->sole();
        $this->assertSame('mskba-client:test-ios', $stored->name);
        $this->assertSame(['profile:read', 'auth:logout'], $stored->abilities);
        $this->assertNotSame($result['access_token'], $stored->token);
        $this->assertNotNull($stored->expires_at);
        $this->assertTrue($stored->expires_at->greaterThan(now()->addDays(29)));

        $this->withToken($result['access_token'])
            ->getJson(route('api.v1.me'))
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.profile.first_name', 'Тест')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.system_role');
    }

    public function test_missing_or_invalid_token_is_unauthorized(): void
    {
        $this->getJson(route('api.v1.me'))->assertUnauthorized();
        $this->withToken('garbage')->getJson(route('api.v1.me'))->assertUnauthorized();
        $this->postJson(route('api.v1.auth.logout'))->assertUnauthorized();
    }

    public function test_bad_credentials_and_bad_request_validation(): void
    {
        $this->account();

        $this->postJson(route('api.v1.auth.login'), [
            'login' => 'mobile_tester', 'password' => 'not-the-password',
        ])->assertUnauthorized();
        $this->postJson(route('api.v1.auth.login'), [
            'login' => 'mobile_tester',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_blocked_user_cannot_login_or_keep_access(): void
    {
        $user = $this->account();
        $token = $this->login()['access_token'];
        $user->forceFill(['status' => UserStatusEnum::BLOCKED])->save();

        $this->postJson(route('api.v1.auth.login'), [
            'login' => 'mobile_tester', 'password' => 'Secret#pass123',
        ])->assertForbidden();

        $this->withToken($token)->getJson(route('api.v1.me'))->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_logout_revokes_only_the_current_device(): void
    {
        $this->account();
        $a = $this->login()['access_token'];
        $b = $this->login()['access_token'];
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withToken($a)->postJson(route('api.v1.auth.logout'))
            ->assertOk();
        $this->withToken($a)->getJson(route('api.v1.me'))->assertUnauthorized();
        $this->withToken($b)->getJson(route('api.v1.me'))
            ->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = $this->account();
        $token = $this->login()['access_token'];
        $user->tokens()->update(['expires_at' => now()->subSecond()]);

        $this->withToken($token)->getJson(route('api.v1.me'))->assertUnauthorized();
    }

    public function test_web_login_still_uses_a_session_not_api_token(): void
    {
        $this->account();

        $this->post(route('auth.login'), [
            'login' => 'mobile_tester', 'password' => 'Secret#pass123',
        ])->assertRedirect();

        $this->assertAuthenticated();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_alias_login_issues_token_for_canonical_account(): void
    {
        $canonical = $this->account('primary_member');
        $alias = User::factory()->create([
            'username' => 'secondary_member',
            'password' => 'Different#pass123',
            'canonical_user_id' => $canonical->id,
        ]);

        $response = $this->login('secondary_member', 'Different#pass123');

        $this->assertSame($canonical->id, $response['user']['id']);
        $this->assertSame($canonical->id, $canonical->tokens()->sole()->tokenable_id);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertTrue(Hash::check('Different#pass123', $alias->password));
    }

    public function test_a_blocked_alias_restricts_existing_canonical_tokens(): void
    {
        $canonical = $this->account();
        $token = $this->login()['access_token'];

        User::factory()->create([
            'username' => 'blocked_alias',
            'canonical_user_id' => $canonical->id,
            'status' => UserStatusEnum::BLOCKED->value,
        ]);

        $this->withToken($token)->getJson(route('api.v1.me'))->assertForbidden();
    }

    public function test_token_with_insufficient_ability_cannot_read_profile(): void
    {
        $user = $this->account();
        $token = $user->createToken('restricted', ['auth:logout'])->plainTextToken;
        $this->withToken($token)->getJson(route('api.v1.me'))->assertForbidden();
        $this->withToken($token)->postJson(route('api.v1.auth.logout'))->assertOk();
    }
}
