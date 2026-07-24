<?php

namespace App\Modules\Telegram\Application\Services;

use App\Modules\Event\Domain\Models\Event;

final class TelegramMiniAppStartDestinationResolver
{
    public function resolve(?string $startParam): ?string
    {
        if (
            $startParam === null
            || preg_match('/\Aevent_(\d+)\z/D', $startParam, $matches) !== 1
        ) {
            return null;
        }

        $event = Event::query()->find((int) $matches[1]);

        if ($event === null) {
            return null;
        }

        return route('events.show', $event->routeIdentifier(), false);
    }
}
