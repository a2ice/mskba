<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;

final class LegacyGameRouteResolver
{
    public function __construct(private readonly LegacyGamesMigrationService $migration) {}

    public function resolve(string $eventIdentifier): Game
    {
        $legacyEvent = Event::query()->whereRouteIdentifier($eventIdentifier)->firstOrFail();

        $game = Game::query()
            ->where('legacy_event_id', $legacyEvent->id)
            ->first();

        return $game ?? $this->migration->ensureMigrated($legacyEvent);
    }
}
