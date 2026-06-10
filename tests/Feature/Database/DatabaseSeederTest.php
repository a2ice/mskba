<?php

namespace Tests\Feature\Database;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\MetroLine;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Venue\Domain\Models\Venue;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_only_base_identity_and_metro_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $superadmin = User::query()
            ->where('username', 'superadmin')
            ->firstOrFail();

        $this->assertTrue(Hash::check('F[etyyj!', $superadmin->password));
        $this->assertSame(UserSystemRoleEnum::SUPERADMIN, $superadmin->system_role);
        $this->assertSame(UserStatusEnum::CONFIRMED, $superadmin->status);
        $this->assertNotNull($superadmin->profile);

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Venue::query()->count());
        $this->assertSame(21, MetroLine::query()->count());
        $this->assertSame(452, MetroStation::query()->count());

        $this->assertDatabaseHas('metro_lines', [
            'name' => 'Сокольническая',
            'color' => 'E42313',
        ]);
        $this->assertDatabaseHas('metro_stations', [
            'name' => 'Сокольники',
        ]);
    }

    public function test_database_seeder_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::query()->where('username', 'superadmin')->count());
        $this->assertSame(1, User::query()->count());
        $this->assertSame(21, MetroLine::query()->count());
        $this->assertSame(452, MetroStation::query()->count());
    }
}
