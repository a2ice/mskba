<?php

namespace Database\Seeders;

use App\Modules\Location\Domain\Models\MetroLine;
use Illuminate\Database\Seeder;

/**
 * The same 21 Moscow metro lines and 452 stations as the production baseline.
 * Can be called independently without provisioning accounts or FAQ content.
 */
final class MoscowMetroSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/moscow_metro.json');
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($data['lines'] as $lineData) {
            $line = MetroLine::query()->updateOrCreate(
                ['name' => $lineData['name']],
                ['color' => $lineData['color'], 'sort_order' => $lineData['sort_order']],
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
}
