<?php

namespace Database\Seeders;

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\MetroLine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    private const SUPERADMIN_USERNAME = 'superadmin';

    private const SUPERADMIN_PASSWORD = 'F[etyyj!';

    /**
     * Seed the application's base production-safe data.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedSuperadmin();
            $this->seedMoscowMetro();
        });
    }

    private function seedSuperadmin(): void
    {
        $user = User::query()->firstOrNew([
            'username' => self::SUPERADMIN_USERNAME,
        ]);

        if (! $user->exists) {
            $user->password = self::SUPERADMIN_PASSWORD;
            $user->password_updated_at = now();
            $user->is_temporary_password = false;
        }

        $user->registration_channel = UserRegistrationChannelEnum::SEED;
        $user->system_role = UserSystemRoleEnum::SUPERADMIN;
        $user->status = UserStatusEnum::CONFIRMED;
        $user->save();

        $user->profile()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'first_name' => 'Супер',
                'last_name' => 'Админ',
            ],
        );
    }

    private function seedMoscowMetro(): void
    {
        foreach ($this->moscowMetroLines() as $lineData) {
            $line = MetroLine::query()->updateOrCreate(
                ['name' => $lineData['name']],
                [
                    'color' => $lineData['color'],
                    'sort_order' => $lineData['sort_order'],
                ],
            );

            foreach ($lineData['stations'] as $stationData) {
                $line->stations()->updateOrCreate(
                    ['name' => $stationData['name']],
                    [
                        'latitude' => $stationData['latitude'],
                        'longitude' => $stationData['longitude'],
                        'sort_order' => $stationData['sort_order'],
                    ],
                );
            }
        }
    }

    /**
     * @return array<int, array{name: string, color: string|null, sort_order: int, stations: array<int, array{name: string, latitude: float|null, longitude: float|null, sort_order: int|null}>}>
     */
    private function moscowMetroLines(): array
    {
        $path = database_path('seeders/data/moscow_metro.json');
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $data['lines'];
    }
}
