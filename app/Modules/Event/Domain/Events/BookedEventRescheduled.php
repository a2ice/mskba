<?php

namespace App\Modules\Event\Domain\Events;

final readonly class BookedEventRescheduled
{
    public function __construct(public int $eventId, public int $bookingId) {}
}
