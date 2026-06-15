<?php

namespace App\Modules\Venue\Infrastructure\Database\Factories;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VenueReview>
 */
class VenueReviewFactory extends Factory
{
    protected $model = VenueReview::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'venue_id' => Venue::factory(),
            'user_id' => User::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'body' => fake()->optional()->paragraph(),
            'is_published' => true,
            'published_at' => fake()->dateTimeBetween('-6 months'),
        ];
    }
}
