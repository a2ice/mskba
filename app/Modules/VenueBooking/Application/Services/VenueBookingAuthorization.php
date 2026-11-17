<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;

final readonly class VenueBookingAuthorization
{
    public function __construct(
        private VenueCommercialAccess $commercialAccess,
        private VenueBookingBackofficeAccess $backoffice,
    ) {}

    public function assertCanConfirmBooking(Actor $actor, Venue $venue, ?VenueBooking $booking = null): void
    {
        if ($booking?->pre_request_id !== null) {
            if ($booking->proposal_accepted_at === null) {
                throw new VenueBookingTransitionException('Сначала клиент должен принять предложение в переписке.', 'PROPOSAL_NOT_ACCEPTED');
            }
            if ($actor->user !== null && app(VenueRentalPreRequests::class)->can($actor->user, $booking->preRequest, 'offer')) {
                return;
            }
        }
        $this->assertBookingPermission($actor, $venue, $booking,
            VenuePermissionEnum::CONFIRM_BOOKING_REQUESTS, 'Недостаточно прав для принятия или подтверждения заявки.');
    }

    public function assertCanRejectBooking(Actor $actor, Venue $venue, ?VenueBooking $booking = null): void
    {
        $this->assertBookingPermission($actor, $venue, $booking,
            VenuePermissionEnum::REJECT_BOOKING_REQUESTS, 'Недостаточно прав для отклонения заявки.');
    }

    private function assertBookingPermission(
        Actor $actor, Venue $venue, ?VenueBooking $booking,
        VenuePermissionEnum $permission, string $message,
    ): void {
        $user = $actor->user;
        if ($user === null || (! $this->allowsBooking($user, $venue, $permission, $booking)
            && ! $this->backoffice->canManage($user))) {
            throw new VenueBookingTransitionException($message, 'BOOKING_FORBIDDEN');
        }
    }

    public function assertCanConfirmPayment(Actor $actor, Venue $venue, ?VenueBooking $booking = null): void
    {
        $user = $actor->user;
        if ($user === null || ! $this->allowsBooking($user, $venue, VenuePermissionEnum::CONFIRM_PAYMENTS, $booking)) {
            throw new VenueBookingTransitionException('Недостаточно прав для проверки оплаты.', 'BOOKING_FORBIDDEN');
        }
    }

    public function assertCanView(Actor $actor, VenueBooking $booking, Venue $venue): void
    {
        if ($booking->pre_request_id !== null) {
            app(VenueRentalPreRequests::class)->assertCan($actor, $booking->preRequest);
            return;
        }
        if ($actor->user_id === $booking->requester_user_id) {
            return;
        }

        $user = $actor->user;
        if ($user === null || (! $this->allowsBooking($user, $venue, VenuePermissionEnum::VIEW_BOOKING_REQUESTS, $booking)
            && ! $this->backoffice->canView($user))) {
            throw new VenueBookingTransitionException('Недостаточно прав для просмотра заявки.', 'BOOKING_FORBIDDEN');
        }
    }

    /** Read-only support may inspect conversations, but cannot post as a participant. */
    public function assertCanWriteConversation(Actor $actor, VenueBooking $booking, Venue $venue): void
    {
        if ($booking->pre_request_id !== null) {
            app(VenueRentalPreRequests::class)->assertCan($actor, $booking->preRequest, 'write');
            return;
        }
        if ($actor->user_id === $booking->requester_user_id) {
            return;
        }
        $user = $actor->user;
        if ($user !== null && ($this->allowsBooking($user, $venue, VenuePermissionEnum::VIEW_BOOKING_REQUESTS, $booking)
            || $this->backoffice->canManage($user))) {
            return;
        }
        throw new VenueBookingTransitionException('Недостаточно прав для переписки по заявке.', 'BOOKING_FORBIDDEN');
    }

    public function assertCanCancel(Actor $actor, VenueBooking $booking, Venue $venue): void
    {
        if ($actor->user_id === $booking->requester_user_id) {
            return;
        }

        $this->assertCanRejectBooking($actor, $venue, $booking);
    }

    private function allowsBooking(User $user, Venue $venue, VenuePermissionEnum $permission, ?VenueBooking $booking): bool
    {
        if ($this->commercialAccess->allows($user, $venue, $permission)) {
            return true;
        }
        if ($booking === null || $booking->venue_court_id === null) {
            return false;
        }
        $court = VenueCourt::query()->where('venue_id', $venue->id)->find($booking->venue_court_id);

        return $court !== null && $this->commercialAccess->allowsForCourt($user, $court, $permission);
    }
}
