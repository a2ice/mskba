<?php

namespace App\Modules\Identity\Infrastructure\Database\Factories;

use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    protected $model = Profile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'middle_name' => rand(0, 1) ? fake()->firstName() : null,
            'gender' => fake()->optional()->randomElement(UserGenderEnum::cases())?->value,
            'birth_date' => fake()->optional()->date(),
        ];
    }
}
