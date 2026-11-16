<?php

namespace App\Modules\VenueBooking\Infrastructure\Listeners;

use App\Modules\VenueBooking\Application\Services\VenueRentalPreRequests;
use App\Modules\VenueBooking\Domain\Events\VenueRentalPreRequestMessageSent;
use Illuminate\Contracts\Queue\ShouldQueue;

final class NotifyVenueRentalPreRequestMessageRecipients implements ShouldQueue
{
    public function handle(VenueRentalPreRequestMessageSent $event): void
    {
        app(VenueRentalPreRequests::class)->notifyMessage($event->messageId);
    }
}
