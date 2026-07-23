<?php

namespace App\Modules\Event\Domain\Events;

final readonly class EventChanged
{
    public function __construct(public int $eventId) {}
}
