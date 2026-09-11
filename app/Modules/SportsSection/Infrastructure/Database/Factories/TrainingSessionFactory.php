<?php

namespace App\Modules\SportsSection\Infrastructure\Database\Factories;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\SportsSection\Domain\Enums\TrainingSessionStatusEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\SportsSection\Domain\Models\TrainingSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TrainingSession> */
final class TrainingSessionFactory extends Factory
{
    protected $model = TrainingSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $startsAt = now()->addDays(fake()->numberBetween(1, 20))->setTime(fake()->numberBetween(9, 20), 0);

        return [
            'sports_section_id' => SportsSection::factory(),
            'created_by_actor_id' => Actor::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(90),
            'status' => TrainingSessionStatusEnum::PLANNED,
        ];
    }
}
