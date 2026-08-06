<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\LegacyGameRoute;

final class LegacyGameRouteResolver
{
    public function __construct(private readonly LegacyGamesMigrationService $migration) {}

    public function resolve(string $eventIdentifier): Game
    {
        $legacyEvent = Event::query()->whereRouteIdentifier($eventIdentifier)->first();
        if ($legacyEvent === null) {
            return LegacyGameRoute::query()
                ->where(function ($query) use ($eventIdentifier): void {
                    $query->where('legacy_identifier', $eventIdentifier);
                    if (preg_match('/^(\d+)-/', $eventIdentifier, $matches) === 1) {
                        $query->orWhere('legacy_event_id', (int) $matches[1]);
                    }
                })
                ->with('game')
                ->firstOrFail()
                ->game;
        }

        $game = Game::query()
            ->where('legacy_event_id', $legacyEvent->id)
            ->first();

        return $game ?? $this->migration->ensureMigrated($legacyEvent);
    }
}
