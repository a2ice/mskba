<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetUserSystemRoleCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_sets_user_system_role_by_username(): void
    {
        $user = User::factory()->create([
            'username' => 'role_user',
            'system_role' => UserSystemRoleEnum::USER,
        ]);

        $this->artisan('identity:set-system-role', [
            'role' => UserSystemRoleEnum::SUPERADMIN->value,
            'username' => 'role_user',
        ])
            ->expectsOutput('User [role_user] system role changed from [user] to [superadmin].')
            ->assertSuccessful();

        $this->assertSame(UserSystemRoleEnum::SUPERADMIN, $user->refresh()->system_role);
    }

    public function test_command_fails_for_unknown_system_role(): void
    {
        User::factory()->create([
            'username' => 'role_user',
            'system_role' => UserSystemRoleEnum::USER,
        ]);

        $this->artisan('identity:set-system-role', [
            'role' => 'unknown',
            'username' => 'role_user',
        ])
            ->expectsOutput('Unknown system role [unknown]. Available roles: superadmin, admin, user, moderator, editor, system.')
            ->assertFailed();
    }

    public function test_command_fails_for_missing_user(): void
    {
        $this->artisan('identity:set-system-role', [
            'role' => UserSystemRoleEnum::ADMIN->value,
            'username' => 'missing_user',
        ])
            ->expectsOutput('User [missing_user] was not found.')
            ->assertFailed();
    }
}
