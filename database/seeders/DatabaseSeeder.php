<?php

namespace Database\Seeders;

use App\Modules\Contact\Domain\Enums\ContactStatusEnum;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserProfile;
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
            'login' => 'superadmin',
            'password' => config('app.debug') ? 'Asdqwe123' : config('app.admin_password'),
            'status' => config('app.debug') ? 'confirmed' : 'unconfirmed',
            'system_role' => 'superadmin',
        ]);

        Contact::factory()->create([
            'entity_type' => 'user',
            'entity_id' => $admin->id,
            'contact_type' => ContactTypeEnum::EMAIL->value,
            'value' => 'superadmin@mskba.ru',
            'status' => config('app.debug') ? ContactStatusEnum::VERIFIED->value : ContactStatusEnum::UNVERIFIED->value,
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
    }
}
