<?php

namespace App\Modules\Location\Infrastructure\Database\Factories;

use App\Modules\Location\Domain\Models\MetroLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetroLine>
 */
class MetroLineFactory extends Factory
{
    protected $model = MetroLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word().' линия',
            'color' => fake()->hexColor(),
            'sort_order' => fake()->numberBetween(1, 20),
        ];
    }
}
