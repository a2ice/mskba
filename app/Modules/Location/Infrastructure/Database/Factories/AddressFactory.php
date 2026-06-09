<?php

namespace App\Modules\Location\Infrastructure\Database\Factories;

use App\Modules\Location\Domain\Models\Address;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    protected $model = Address::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $street = fake()->streetName();
        $building = (string) fake()->numberBetween(1, 150);

        return [
            'postal_code' => fake()->postcode(),
            'city' => 'Москва',
            'street' => $street,
            'building' => $building,
            'latitude' => fake()->latitude(55.55, 55.95),
            'longitude' => fake()->longitude(37.30, 37.95),
            'full_address' => "Москва, {$street}, {$building}",
        ];
    }
}
