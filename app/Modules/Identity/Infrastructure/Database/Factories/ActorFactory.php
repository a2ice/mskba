<?php

namespace App\Modules\Identity\Infrastructure\Database\Factories;

use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Actor>
 */
class ActorFactory extends Factory
{
    protected $model = Actor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = fake()->unique()->uuid();

        return [
            'actor_key' => 'test:'.$key,
            'type' => ActorTypeEnum::SYSTEM->value,
            'user_id' => null,
            'user_fingerprint_id' => null,
        ];
    }
}
