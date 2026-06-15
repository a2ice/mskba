<?php

namespace App\Modules\Venue\Infrastructure\Database\Factories;

use App\Modules\Venue\Domain\Models\VenueSchedule;
use App\Modules\Venue\Domain\Models\VenueScheduleInterval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VenueScheduleInterval>
 */
class VenueScheduleIntervalFactory extends Factory
{
    protected $model = VenueScheduleInterval::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startHour = fake()->numberBetween(8, 20);

        return [
            'venue_schedule_id' => VenueSchedule::factory(),
            'day_of_week' => fake()->numberBetween(1, 7),
            'starts_at' => sprintf('%02d:00', $startHour),
            'ends_at' => sprintf('%02d:00', min($startHour + fake()->numberBetween(1, 3), 23)),
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
