<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class MobileRegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return array_merge([
            'username' => 'native_new_player',
            'password' => 'StrongPass1!',
            'password_confirmation' => 'StrongPass1!',
            'privacy_consent' => true,
            'device_name' => 'ios-dev',
        ], $extra);
    }

    public function test_registration_creates_a_user_with_audited_consent_and_an_usable_scoped_token(): void
    {
        $response = $this->postJson(route('api.v1.auth.register'), $this->payload([
            'username' => '  Native_Player  ',
            'role' => UserParticipationRoleEnum::PLAYER->value,
            'first_name' => 'Никита',
            'last_name' => 'Тестовый',
            'gender' => 'male',
            'birth_date' => '2001-01-15',
            'height_cm' => 181,
            'position' => 'point_guard',
        ]))->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('requires_password_change', false)
            ->assertJsonPath('user.username', 'native_player')
            ->assertJsonPath('user.status', 'unconfirmed')
            ->assertJsonPath('user.profile.first_name', 'Никита')
            ->assertJsonPath('user.participation_roles.0', 'player');

        $this->assertGuest();
        $json = $response->json();
        $this->assertNotEmpty($json['access_token']);
        $this->assertNotEmpty($json['expires_at']);
        $user = User::query()->where('username', 'native_player')->firstOrFail();

        $this->assertTrue(Hash::check('StrongPass1!', $user->password));
        $this->assertSame('mobile_app', $user->registration_channel->value);
        $this->assertNotNull($user->personal_data_distribution_required_at);
        $this->assertNull($user->personal_data_distribution_setup_completed_at);
        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'type' => UserConsent::TYPE_PERSONAL_DATA_PROCESSING,
            'source' => 'mobile_api_registration',
            'document_version' => config('legal.personal_data_consent_version'),
        ]);
        $this->assertDatabaseHas('player_profiles', ['user_id' => $user->id, 'height_cm' => 181]);
        $this->assertDatabaseHas('player_profile_positions', ['position' => 'point_guard']);
        $token = $user->tokens()->sole();
        $this->assertSame('mskba-client:ios-dev', $token->name);
        $this->assertSame(['profile:read', 'auth:logout', 'account:delete'], $token->abilities);
        $this->assertTrue($token->expires_at->greaterThan(now()->addDays(29)));

        $this->withToken($json['access_token'])->getJson(route('api.v1.me'))
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_registration_with_minimal_payload_uses_default_device_name_without_a_role(): void
    {
        $response = $this->postJson(route('api.v1.auth.register'), $this->payload([
            'username' => 'minimal_mobile',
            'device_name' => null,
        ]));
        $response->assertUnprocessable()->assertJsonValidationErrors('device_name');

        $minimal = $this->payload(['username' => 'minimal_mobile']);
        unset($minimal['device_name']);
        $this->postJson(route('api.v1.auth.register'), $minimal)
            ->assertCreated()
            ->assertJsonPath('user.participation_roles', []);
        $user = User::query()->where('username', 'minimal_mobile')->firstOrFail();
        $this->assertSame('mskba-client:mobile', $user->tokens()->sole()->name);
    }

    public function test_registration_rejects_duplicate_login_and_does_not_issue_token(): void
    {
        User::factory()->create(['username' => 'taken_login']);
        $this->postJson(route('api.v1.auth.register'), $this->payload([
            'username' => 'TAKEN_LOGIN',
        ]))->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_registration_rejects_missing_consent_and_invalid_player_data_without_creating_account(): void
    {
        $this->postJson(route('api.v1.auth.register'), $this->payload([
            'username' => 'no_consent',
            'privacy_consent' => false,
        ]))->assertUnprocessable()->assertJsonValidationErrors('privacy_consent');

        $this->postJson(route('api.v1.auth.register'), $this->payload([
            'username' => 'bad_player',
            'role' => 'player',
            'height_cm' => 300,
            'position' => 'invalid',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['height_cm', 'position']);

        $this->assertDatabaseMissing('users', ['username' => 'no_consent']);
        $this->assertDatabaseMissing('users', ['username' => 'bad_player']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
