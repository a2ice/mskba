<?php

namespace App\Modules\Identity\Infrastructure\Database\Factories\Participation;

use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Domain\Models\Participation\PlayerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerProfile>
 */
class PlayerProfileFactory extends Factory
{
    protected $model = PlayerProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'height_cm' => fake()->optional()->numberBetween(155, 220),
            'weight_kg' => fake()->optional()->randomFloat(1, 50, 140),
            'position' => fake()->optional()->randomElement(PlayerPositionEnum::cases())?->value,
            'experience_started_year' => fake()->optional()->numberBetween(1990, now()->year),
            'comment' => fake()->optional()->sentence(),
            'extra' => null,
        ];
    }
}
