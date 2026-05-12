<?php

namespace Database\Seeders;

use App\Modules\Contact\Domain\Enums\ContactStatusEnum;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
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
            'email' => 'demo@example.com',
            'password' => 'password',
        ]);

        $withCode = User::factory()->create([
            'login' => 'with-code',
            'email' => 'with-code@example.com',
            'password' => null,
        ]);

        Contact::factory()->create([
            'entity_type' => 'user',
            'entity_id' => $withCode->id,
            'contact_type' => ContactTypeEnum::PHONE->value,
            'value' => '+79991234567',
            'status' => ContactStatusEnum::VERIFIED->value,
        ]);

        User::factory()->create([
            'login' => null,
            'email' => 'email-only@example.com',
            'password' => null,
        ]);

        User::factory(5)->create();
    }
}
