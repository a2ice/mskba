<?php

namespace App\Modules\Tournament\Infrastructure\Database\Factories;

use App\Modules\Identity\Infrastructure\Database\Factories\ActorFactory;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Tournament> */
class TournamentFactory extends Factory
{
    protected $model = Tournament::class;

    public function definition(): array
    {
        $title = fake()->words(3, true);

        return [
            'created_by_actor_id' => ActorFactory::new(),
            'title' => $title,
            'alias' => Str::slug($title),
            'status' => TournamentStatusEnum::CONFIRMED,
            'starts_on' => today()->addWeek(),
            'ends_on' => today()->addWeeks(2),
            'short_description' => fake()->optional()->sentence(),
            'full_description' => fake()->optional()->paragraph(),
        ];
    }
}
