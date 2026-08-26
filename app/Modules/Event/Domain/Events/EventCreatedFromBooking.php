<?php

namespace App\Modules\Event\Domain\Events;

final readonly class EventCreatedFromBooking
{
    public function __construct(public int $eventId, public int $bookingId) {}
}
