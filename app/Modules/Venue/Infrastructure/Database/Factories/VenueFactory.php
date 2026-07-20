<?php

namespace App\Modules\Venue\Infrastructure\Database\Factories;

use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    protected $model = Venue::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Баскетбол';

        return [
            'name' => $name,
            'alias' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'type' => fake()->randomElement(VenueTypeEnum::cases())->value,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'location_id' => Location::factory(),
            'short_description' => fake()->optional()->sentence(),
            'full_description' => fake()->optional()->paragraph(),
            'raw_address' => fake()->optional()->address(),
        ];
    }
}
