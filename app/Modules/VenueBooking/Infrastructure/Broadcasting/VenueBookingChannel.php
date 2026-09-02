<?php

namespace App\Modules\VenueBooking\Infrastructure\Broadcasting;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;

final readonly class VenueBookingChannel
{
    public function __construct(private CurrentActorResolver $actors, private VenueBookingAuthorization $authorization) {}

    public function join(User $user, string $publicId): bool
    {
        $booking = VenueBooking::query()->with('venue')->where('public_id', $publicId)->first();
        if ($booking === null) {
            return false;
        }

        try {
            $this->authorization->assertCanView($this->actors->resolve($user, null), $booking, $booking->venue);

            return true;
        } catch (VenueBookingTransitionException) {
            return false;
        }
    }
}
