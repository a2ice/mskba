<?php

namespace App\Modules\Location\Infrastructure\Database\Factories;

use App\Modules\Location\Domain\Models\MetroLine;
use App\Modules\Location\Domain\Models\MetroStation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetroStation>
 */
class MetroStationFactory extends Factory
{
    protected $model = MetroStation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'metro_line_id' => MetroLine::factory(),
            'name' => fake()->unique()->city(),
            'latitude' => fake()->latitude(55.55, 55.95),
            'longitude' => fake()->longitude(37.30, 37.95),
            'sort_order' => fake()->numberBetween(1, 250),
        ];
    }
}
