<?php

namespace App\Modules\VenueBooking\Infrastructure\Listeners;

use App\Modules\VenueBooking\Domain\Events\VenueBookingCancelled;
use App\Modules\VenueBooking\Domain\Events\VenueBookingConfirmed;
use App\Modules\VenueBooking\Domain\Events\VenueBookingExpired;
use App\Modules\VenueBooking\Domain\Events\VenueBookingHeld;
use App\Modules\VenueBooking\Domain\Events\VenueBookingRejected;
use App\Modules\VenueBooking\Domain\Events\VenueBookingRequested;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversation;
use App\Modules\VenueBooking\Domain\Models\VenueBookingMessage;
use App\Modules\VenueBooking\Infrastructure\Broadcasting\EventVenueInterestMessageSentBroadcast;
use Illuminate\Support\Str;

/** Append-only system timeline inside the existing pre-request conversation. */
final readonly class AppendInterestBookingLifecycleMessage
{
    public function handle(
        VenueBookingRequested|VenueBookingHeld|VenueBookingConfirmed|VenueBookingRejected|VenueBookingCancelled|VenueBookingExpired $event,
    ): void {
        $booking = VenueBooking::query()->with('latestTransition')
            ->find($event->bookingId);
        if ($booking?->event_venue_interest_id === null || $booking->latestTransition?->actor_id === null) {
            return;
        }
        $conversation = VenueBookingConversation::query()
            ->where('event_venue_interest_id', $booking->event_venue_interest_id)->first();
        if ($conversation === null) {
            return;
        }

        $status = $booking->status->label();
        $transition = $booking->latestTransition;
        // Stable idempotency token: outbox messages may be retried.
        $hex = md5('interest-booking:'.$booking->id.':'.$transition->booking_version);
        $clientId = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
        $message = VenueBookingMessage::query()->firstOrCreate([
            'conversation_id' => $conversation->id,
            'author_actor_id' => $transition->actor_id,
            'client_id' => $clientId,
        ], [
            'public_id' => (string) Str::uuid(),
            'type' => 'system',
            'body' => 'Изменён статус заявки на аренду: '.$status.'.'.($transition->reason ? ' Причина: '.$transition->reason : ''),
        ]);
        if ($message->wasRecentlyCreated) {
            broadcast(new EventVenueInterestMessageSentBroadcast($conversation->public_id, $message->public_id));
        }
    }
}
