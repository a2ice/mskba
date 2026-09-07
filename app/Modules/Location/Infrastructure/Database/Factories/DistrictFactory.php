<?php

namespace App\Modules\Location\Infrastructure\Database\Factories;

use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<District>
 */
class DistrictFactory extends Factory
{
    protected $model = District::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'name' => fake()->streetName().' район',
            'alias' => fake()->unique()->slug(2),
            'short_name' => null,
            'description' => fake()->optional()->sentence(),
        ];
    }
}
