<?php

namespace App\Modules\Event\Application\DTO;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;

final readonly class EventSubmissionResult
{
    private function __construct(
        public ?Event $event,
        public ?VenueBooking $booking,
    ) {}

    public static function event(Event $event): self
    {
        return new self($event, null);
    }

    public static function booking(VenueBooking $booking): self
    {
        return new self(null, $booking);
    }
}
