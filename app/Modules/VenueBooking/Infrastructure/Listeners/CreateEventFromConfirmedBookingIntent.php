<?php

namespace App\Modules\VenueBooking\Infrastructure\Listeners;

use App\Modules\VenueBooking\Application\UseCases\CreateEventFromConfirmedVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Events\VenueBookingConfirmed;
use App\Modules\VenueBooking\Domain\Models\VenueBookingEventIntent;

final readonly class CreateEventFromConfirmedBookingIntent
{
    public function __construct(private CreateEventFromConfirmedVenueBookingHandler $events) {}

    public function handle(VenueBookingConfirmed $event): void
    {
        $intent = VenueBookingEventIntent::query()
            ->with('creatorActor.user')
            ->where('venue_booking_id', $event->bookingId)
            ->first();

        if ($intent === null) {
            return;
        }

        $this->events->handle(
            $event->bookingId,
            $intent->creatorActor,
            [
                ...$intent->event_payload,
                'publish_to_telegram' => $intent->telegram_chat_ids !== null,
                'telegram_chat_ids' => $intent->telegram_chat_ids ?? [],
            ],
        );
    }
}
