<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use InvalidArgumentException;

final class CreateStandaloneGameHandler
{
    public function __construct(private readonly CreateEventHandler $events) {}

    /** @param array<string, mixed> $data */
    public function handle(Actor $actor, array $data): Event
    {
        if (($data['type'] ?? null) !== EventTypeEnum::GAME->value) {
            throw new InvalidArgumentException('Standalone-сценарий создаёт только мероприятие типа «Игра».');
        }

        $event = $this->events->handle($actor, $data);

        if ($event->primary_game_id === null) {
            throw new InvalidArgumentException('Не удалось создать спортивную часть игры.');
        }

        return $event;
    }
}
