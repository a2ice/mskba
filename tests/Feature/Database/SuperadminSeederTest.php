<?php

namespace Tests\Feature\Database;

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Database\Seeders\SuperadminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class SuperadminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_an_environment_specific_secret_for_creation(): void
    {
        config()->set('seeding.superadmin_password', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SUPERADMIN_SEED_PASSWORD');
        $this->seed(SuperadminSeeder::class);
    }

    public function test_it_creates_a_confirmed_superadmin_once_without_rotating_its_password(): void
    {
        config()->set('seeding.superadmin_password', 'dev-only-initial-secret-12345');
        $this->seed(SuperadminSeeder::class);

        $user = User::where('username', 'superadmin')->firstOrFail();
        $hash = $user->password;
        $this->assertTrue(Hash::check('dev-only-initial-secret-12345', $hash));
        $this->assertSame(UserSystemRoleEnum::SUPERADMIN, $user->system_role);
        $this->assertSame(UserStatusEnum::CONFIRMED, $user->status);
        $this->assertSame(UserRegistrationChannelEnum::SEED, $user->registration_channel);
        $this->assertSame('Супер', $user->profile->first_name);

        config()->set('seeding.superadmin_password', 'different-secret-after-deploy');
        $this->seed(SuperadminSeeder::class);

        $this->assertSame(1, User::where('username', 'superadmin')->count());
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_it_refuses_to_upgrade_an_existing_non_admin_account(): void
    {
        User::factory()->create([
            'username' => 'superadmin',
            'system_role' => UserSystemRoleEnum::USER,
        ]);
        config()->set('seeding.superadmin_password', 'dev-only-initial-secret-12345');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manual review');
        $this->seed(SuperadminSeeder::class);
    }

    public function test_it_does_not_create_or_change_users_in_production(): void
    {
        $originalEnvironment = app()->environment();
        app()->instance('env', 'production');

        try {
            config()->set('seeding.superadmin_password', 'would-be-production-password');
            (new SuperadminSeeder)->run();
            $this->assertSame(0, User::count());
        } finally {
            app()->instance('env', $originalEnvironment);
        }
    }
}
