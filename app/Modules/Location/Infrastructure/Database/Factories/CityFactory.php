<?php

namespace App\Modules\Location\Infrastructure\Database\Factories;

use App\Modules\Location\Domain\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    protected $model = City::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->city(),
            'alias' => fake()->unique()->slug(2),
            'short_name' => null,
            'description' => fake()->optional()->sentence(),
        ];
    }
}
