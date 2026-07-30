<?php

namespace App\Modules\Event\Domain\Events;

final readonly class GameStatisticsConfirmed
{
    public function __construct(public int $eventId) {}
}
