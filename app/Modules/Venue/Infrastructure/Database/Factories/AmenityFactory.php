<?php

namespace App\Modules\Venue\Infrastructure\Database\Factories;

use App\Modules\Venue\Domain\Models\Amenity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Amenity>
 */
class AmenityFactory extends Factory
{
    protected $model = Amenity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::headline($name),
            'alias' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'description' => fake()->optional()->sentence(),
            'icon' => fake()->optional()->randomElement(['ti-shirt', 'ti-parking', 'ti-shower', 'ti-ball-basketball']),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
