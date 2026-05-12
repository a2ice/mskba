<?php

namespace Database\Seeders;

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
        User::factory()->create([
            'login' => 'demo',
            // 'email' => 'demo@example.com',
            'password' => 'password',
        ]);

        User::factory()->create([
            'login' => 'with-code',
            // 'email' => 'with-code@example.com',
            'password' => null,
        ]);

        User::factory()->create([
            'login' => null,
            // 'email' => 'email-only@example.com',
            'password' => null,
        ]);

        User::factory(5)->create();
    }
}
