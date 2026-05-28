<?php

namespace Database\Seeders;

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // create super admin user
        $superadmin = User::factory()->create([
            'username' => 'superadmin',
            'password' => 'superadmin_password',
            'is_temporary_password' => false,
            'registration_channel' => UserRegistrationChannelEnum::SEED->value,
            'system_role' => UserSystemRoleEnum::SUPERADMIN->value,
            'status' => UserStatusEnum::CONFIRMED->value,
        ]);
        $superadmin->profile()->create([
            'first_name' => 'Супер',
            'last_name' => 'Админ',
        ]);

        // create admin user
        $admin = User::factory()->create([
            'username' => 'admin',
            'password' => 'admin_password',
            'is_temporary_password' => false,
            'registration_channel' => UserRegistrationChannelEnum::SEED->value,
            'system_role' => UserSystemRoleEnum::ADMIN->value,
            'status' => UserStatusEnum::CONFIRMED->value,
        ]);
        $admin->profile()->create([
            'first_name' => 'Админ',
            'last_name' => 'Пользователь',
        ]);

        // create moderator user
        $moderator = User::factory()->create([
            'username' => 'moderator',
            'password' => 'moderator_password',
            'is_temporary_password' => false,
            'registration_channel' => UserRegistrationChannelEnum::SEED->value,
            'system_role' => UserSystemRoleEnum::MODERATOR->value,
            'status' => UserStatusEnum::CONFIRMED->value,
        ]);
        $moderator->profile()->create([
            'first_name' => 'Модератор',
            'last_name' => 'Пользователь',
        ]);

        // create editor user
        $editor = User::factory()->create([
            'username' => 'editor',
            'password' => 'editor_password',
            'is_temporary_password' => false,
            'registration_channel' => UserRegistrationChannelEnum::SEED->value,
            'system_role' => UserSystemRoleEnum::EDITOR->value,
            'status' => UserStatusEnum::CONFIRMED->value,
        ]);
        $editor->profile()->create([
            'first_name' => 'Редактор',
            'last_name' => 'Пользователь',
        ]);

        // create regular users with profiles
        User::factory(10)
            ->has(Profile::factory())
            ->create();

    }
}
