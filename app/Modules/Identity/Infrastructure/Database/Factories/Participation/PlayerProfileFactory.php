<?php

namespace App\Modules\Identity\Infrastructure\Database\Factories\Participation;

use App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum;
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
            'height_cm' => fake()->optional()->numberBetween(150, 220),
            'weight_kg' => fake()->optional()->numberBetween(40, 120),
            'body_type' => fake()->optional()->randomElement(PlayerBodyTypeEnum::cases())?->value,
            'experience_started_year' => fake()->optional()->numberBetween(now()->year - 70, now()->year - 10),
            'comment' => fake()->optional()->sentence(),
            'extra' => null,
        ];
    }
}
