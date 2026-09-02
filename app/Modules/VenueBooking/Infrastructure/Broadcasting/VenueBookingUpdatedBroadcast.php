<?php

namespace App\Modules\VenueBooking\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final readonly class VenueBookingUpdatedBroadcast implements ShouldBroadcastNow
{
    public function __construct(public string $bookingId, public int $version) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('venue-bookings.'.$this->bookingId);
    }

    public function broadcastAs(): string
    {
        return 'booking.updated';
    }

    /** @return array{booking_id: string, version: int} */
    public function broadcastWith(): array
    {
        return ['booking_id' => $this->bookingId, 'version' => $this->version];
    }
}
