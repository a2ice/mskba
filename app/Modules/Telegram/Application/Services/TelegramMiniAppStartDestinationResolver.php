<?php

namespace App\Modules\Telegram\Application\Services;

use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Event\Domain\Models\Event;

final class TelegramMiniAppStartDestinationResolver
{
    public function resolve(?string $startParam): ?string
    {
        if ($startParam === null) {
            return null;
        }

        if (preg_match('/\Aevent_(\d+)\z/D', $startParam, $matches) === 1) {
            $event = Event::query()->find((int) $matches[1]);

            return $event === null
                ? null
                : route('events.show', $event->routeIdentifier(), false);
        }

        if (preg_match('/\Acoordination_(\d+)\z/D', $startParam, $matches) === 1) {
            $coordination = CoordinationSession::query()->find((int) $matches[1]);

            return $coordination === null
                ? null
                : route('coordination.show', $coordination, false);
        }

        return null;
    }
}
