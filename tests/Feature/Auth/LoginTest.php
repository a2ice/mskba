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
