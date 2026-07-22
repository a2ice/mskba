<?php

namespace App\Modules\Event\Infrastructure\Database\Factories;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Infrastructure\Database\Factories\ActorFactory;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Event> */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $title = fake()->sentence(3);
        $startsAt = now()->addDays(2)->setTime(18, 0);

        return [
            'venue_id' => Venue::factory(),
            'organizer_actor_id' => ActorFactory::new(),
            'title' => $title,
            'alias' => Str::slug($title),
            'type' => fake()->randomElement(EventTypeEnum::cases())->value,
            'status' => EventStatusEnum::PUBLISHED->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'description' => fake()->optional()->paragraph(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(90),
            'max_participants' => 10,
        ];
    }
}
