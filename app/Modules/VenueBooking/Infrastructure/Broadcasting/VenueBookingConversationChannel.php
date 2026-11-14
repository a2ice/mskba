<?php

namespace App\Modules\VenueBooking\Infrastructure\Broadcasting;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Event\Application\Services\EventVenueInterestService;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversation;

final readonly class VenueBookingConversationChannel
{
    public function __construct(private CurrentActorResolver $actors, private VenueBookingAuthorization $authorization) {}

    public function join(User $user, string $publicId): bool
    {
        $conversation = VenueBookingConversation::query()->with(['booking.venue', 'interest.venue', 'interest.court', 'interest.event.organizerActor'])->where('public_id', $publicId)->first();
        if ($conversation === null) {
            return false;
        }

        if ($conversation->interest !== null && app(EventVenueInterestService::class)->canParticipateInThread($user, $conversation->interest)) {
            return true;
        }
        if ($conversation->booking === null) {
            return false;
        }

        try {
            $this->authorization->assertCanView($this->actors->resolve($user, null), $conversation->booking, $conversation->booking->venue);

            return true;
        } catch (VenueBookingTransitionException) {
            return false;
        }
    }
}
