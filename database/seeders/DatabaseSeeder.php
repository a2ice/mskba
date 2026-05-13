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
        $admin = User::factory()->create([
            'login' => 'admin',
            'password' => 'Asdqwe123',
            'status' => 'confirmed',
        ]);

        Contact::factory()->create([
            'entity_type' => 'user',
            'entity_id' => $admin->id,
            'contact_type' => ContactTypeEnum::EMAIL->value,
            'value' => 'admin@mskba.ru',
            'status' => ContactStatusEnum::VERIFIED->value,
        ]);
    
        $unconfirmed = User::factory()->create([
            'login' => 'unconfirmed',
            'password' => 'Asdqwe123',
            'status' => 'unconfirmed',
        ]);

        Contact::factory()->create([
            'entity_type' => 'user',
            'entity_id' => $unconfirmed->id,
            'contact_type' => ContactTypeEnum::EMAIL->value,
            'value' => 'unconfirmed@mskba.ru',
            'status' => ContactStatusEnum::UNVERIFIED->value,
        ]);

        // $withCode = User::factory()->confirmed()->create([
        //     'login' => 'with-code',
        //     'password' => 'password',
        // ]);

        // Contact::factory()->create([
        //     'entity_type' => 'user',
        //     'entity_id' => $withCode->id,
        //     'contact_type' => ContactTypeEnum::EMAIL->value,
        //     'value' => 'with-code@example.com',
        //     'status' => ContactStatusEnum::VERIFIED->value,
        // ]);

        // Contact::factory()->create([
        //     'entity_type' => 'user',
        //     'entity_id' => $withCode->id,
        //     'contact_type' => ContactTypeEnum::PHONE->value,
        //     'value' => '+79991234567',
        //     'status' => ContactStatusEnum::VERIFIED->value,
        // ]);

        // $emailOnly = User::factory()->confirmed()->create([
        //     'login' => null,
        //     'password' => 'password',
        // ]);

        // Contact::factory()->create([
        //     'entity_type' => 'user',
        //     'entity_id' => $emailOnly->id,
        //     'contact_type' => ContactTypeEnum::EMAIL->value,
        //     'value' => 'email-only@example.com',
        //     'status' => ContactStatusEnum::VERIFIED->value,
        // ]);

        // User::factory(5)->confirmed()->create();
    }
}
