<?php

namespace App\Modules\Venue\Infrastructure\Database\Factories;

use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VenueSchedule>
 */
class VenueScheduleFactory extends Factory
{
    protected $model = VenueSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'venue_id' => Venue::factory(),
            'timezone' => 'Europe/Moscow',
        ];
    }
}
