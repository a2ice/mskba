<?php

namespace Database\Seeders;

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        // Production provisioning is intentionally manual and separately approved.
        if (app()->environment('production')) {
            return;
        }

        $existing = User::withTrashed()->where('username', 'superadmin')->first();

        if ($existing !== null) {
            // Never restore a deleted account or promote an unrelated account.
            if ($existing->trashed() || $existing->system_role !== UserSystemRoleEnum::SUPERADMIN) {
                throw new RuntimeException('Existing superadmin username requires manual review.');
            }

            // Preserve existing password, status, role and profile on later deploys.
            return;
        }

        $password = config('seeding.superadmin_password');

        if (! is_string($password) || strlen($password) < 20) {
            throw new RuntimeException('SUPERADMIN_SEED_PASSWORD must be configured with at least 20 characters.');
        }

        DB::transaction(function () use ($password): void {
            $user = User::query()->create([
                'username' => 'superadmin',
                'password' => $password,
                'password_updated_at' => now(),
                'is_temporary_password' => false,
                'registration_channel' => UserRegistrationChannelEnum::SEED,
                'system_role' => UserSystemRoleEnum::SUPERADMIN,
                'status' => UserStatusEnum::CONFIRMED,
            ]);

            $user->profile()->create([
                'first_name' => 'Супер',
                'last_name' => 'Админ',
            ]);
        });
    }
}
