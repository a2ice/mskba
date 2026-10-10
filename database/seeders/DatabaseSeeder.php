<?php

namespace Database\Seeders;

use App\Modules\Location\Domain\Models\MetroLine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's base production-safe data.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call(SuperadminSeeder::class);
            $this->call(GeographySeeder::class);
            $this->call(FaqContentSeeder::class);
            $this->seedMoscowMetro();
        });
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
