<?php

namespace App\Modules\Telegram\Infrastructure\Listeners;

use App\Modules\Telegram\Domain\Models\TelegramVenueRentalPublication;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramVenueRentalPublicationJob;
use App\Modules\VenueBooking\Domain\Events\VenueBookingCancelled;
use App\Modules\VenueBooking\Domain\Events\VenueBookingConfirmed;
use App\Modules\VenueBooking\Domain\Events\VenueBookingExpired;
use App\Modules\VenueBooking\Domain\Events\VenueBookingHeld;
use App\Modules\VenueBooking\Domain\Events\VenueBookingRejected;
use App\Modules\VenueBooking\Domain\Events\VenueBookingRequested;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class QueueTelegramVenueRentalBookingSync
{
    public function __construct(private FeatureFlags $features) {}

    public function handle(
        VenueBookingRequested|VenueBookingHeld|VenueBookingConfirmed|VenueBookingRejected|VenueBookingCancelled|VenueBookingExpired $event,
    ): void {
        if (! $this->features->enabled(VenueRentalFeature::COORDINATION)) {
            return;
        }

        TelegramVenueRentalPublication::query()
            ->where(function ($query) use ($event): void {
                $query->where('venue_booking_id', $event->bookingId)
                    ->orWhereHas('coordination', fn ($coordination) => $coordination->where('venue_booking_id', $event->bookingId));
            })
            ->get()
            ->each(function (TelegramVenueRentalPublication $publication) use ($event): void {
                if ($publication->venue_booking_id === null) {
                    $publication->update(['venue_booking_id' => $event->bookingId]);
                }
                SyncTelegramVenueRentalPublicationJob::dispatch($publication->id)->afterCommit();
            });
    }
}
