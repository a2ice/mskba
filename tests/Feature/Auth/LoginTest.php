<?php

namespace Tests\Feature\Auth;

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_username_and_password(): void
    {
        $user = User::factory()->create([
            'username' => 'scaffold_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $response = $this->post(route('auth.login'), [
            'login' => 'scaffold_user',
            'password' => 'password',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_inline_help_login_returns_fresh_csrf_token_without_navigation_for_complete_account(): void
    {
        $user = User::factory()->create([
            'username' => 'help_login_user',
            'password' => 'password',
            'status' => UserStatusEnum::CONFIRMED,
            'personal_data_distribution_required_at' => null,
        ]);

        $this->postJson(route('auth.login'), [
            'login' => 'help_login_user',
            'password' => 'password',
            'redirect_to' => '/venues',
            'inline_auth' => true,
        ])->assertOk()
            ->assertJsonPath('inline_auth', true)
            ->assertJsonPath('requires_setup', false)
            ->assertJsonStructure(['csrf_token', 'redirect_url']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_inline_login_still_requires_mandatory_privacy_setup(): void
    {
        User::factory()->create([
            'username' => 'help_pending_user',
            'password' => 'password',
            'status' => UserStatusEnum::CONFIRMED,
            'personal_data_distribution_required_at' => now(),
            'personal_data_distribution_setup_completed_at' => null,
        ]);

        $this->postJson(route('auth.login'), [
            'login' => 'help_pending_user',
            'password' => 'password',
            'redirect_to' => '/venues',
            'inline_auth' => true,
        ])->assertOk()
            ->assertJsonPath('inline_auth', false)
            ->assertJsonPath('requires_setup', true);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create([
            'username' => 'logout_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $response = $this->actingAs($user)->post(route('auth.logout'));

        $response->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_blocked_user_cannot_login(): void
    {
        User::factory()->create([
            'username' => 'blocked_user',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::BLOCKED,
        ]);

        $response = $this->post(route('auth.login'), [
            'login' => 'blocked_user',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest();
    }
}
