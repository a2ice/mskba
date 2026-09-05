<?php

namespace App\Modules\VenueBooking\Infrastructure\Listeners;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationDeliveryCategoryEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationSourceEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPartyRole;
use App\Modules\VenueBooking\Domain\Events\VenueBookingCancelled;
use App\Modules\VenueBooking\Domain\Events\VenueBookingConfirmed;
use App\Modules\VenueBooking\Domain\Events\VenueBookingExpired;
use App\Modules\VenueBooking\Domain\Events\VenueBookingHeld;
use App\Modules\VenueBooking\Domain\Events\VenueBookingRejected;
use App\Modules\VenueBooking\Domain\Events\VenueBookingRequested;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Illuminate\Contracts\Queue\ShouldQueue;

final readonly class NotifyVenueBookingLifecycleRecipients implements ShouldQueue
{
    public function __construct(
        private CreateUserNotificationHandler $notifications,
        private VenueMembershipAccess $memberships,
    ) {}

    public function handle(
        VenueBookingRequested|VenueBookingHeld|VenueBookingConfirmed|VenueBookingRejected|VenueBookingCancelled|VenueBookingExpired $event,
    ): void {
        $booking = VenueBooking::query()
            ->with(['venue', 'requester', 'parties'])
            ->find($event->bookingId);

        if ($booking === null || $booking->venue === null) {
            return;
        }

        [$source, $title, $body] = match ($event::class) {
            VenueBookingRequested::class => [
                UserNotificationSourceEnum::VENUE_BOOKING_REQUESTED,
                'Новая заявка на аренду',
                "Получена новая заявка на аренду площадки «{$booking->venue->name}».",
            ],
            VenueBookingHeld::class => [
                UserNotificationSourceEnum::VENUE_BOOKING_HELD,
                'Заявка на аренду принята',
                "Представитель площадки «{$booking->venue->name}» принял заявку в работу.",
            ],
            VenueBookingConfirmed::class => [
                UserNotificationSourceEnum::VENUE_BOOKING_CONFIRMED,
                'Аренда подтверждена',
                "Бронирование площадки «{$booking->venue->name}» подтверждено.",
            ],
            VenueBookingRejected::class => [
                UserNotificationSourceEnum::VENUE_BOOKING_REJECTED,
                'Заявка на аренду отклонена',
                "Заявка на аренду площадки «{$booking->venue->name}» отклонена.",
            ],
            VenueBookingCancelled::class => [
                UserNotificationSourceEnum::VENUE_BOOKING_CANCELLED,
                'Заявка на аренду отменена',
                "Заявка на аренду площадки «{$booking->venue->name}» отменена.",
            ],
            VenueBookingExpired::class => [
                UserNotificationSourceEnum::VENUE_BOOKING_EXPIRED,
                'Заявка на аренду истекла',
                "Срок действия заявки на аренду площадки «{$booking->venue->name}» истёк.",
            ],
        };

        foreach ($this->recipientIds($booking, $event) as $userId) {
            $this->notifications->handle(new CreateUserNotificationDTO(
                userId: $userId,
                type: UserNotificationTypeEnum::SYSTEM,
                title: $title,
                body: $body,
                actionUrl: route('account.venue-bookings.show', $booking, false),
                actionText: 'Открыть заявку',
                payload: [
                    'source' => $source->value,
                    'delivery_category' => UserNotificationDeliveryCategoryEnum::REQUEST->value,
                    'booking_id' => $booking->public_id,
                    'venue_id' => $booking->venue_id,
                ],
            ));
        }
    }

    /** @return list<int> */
    private function recipientIds(VenueBooking $booking, object $event): array
    {
        $ids = match ($event::class) {
            VenueBookingRequested::class => $this->memberships->allowedUserIdsForVenue(
                $booking->venue,
                VenuePermissionEnum::DECIDE_BOOKING_REQUESTS,
            ),
            VenueBookingCancelled::class => [
                $booking->requester_user_id,
                ...$booking->parties
                    ->where('role', VenueBookingPartyRole::VENUE_REPRESENTATIVE)
                    ->pluck('user_id')
                    ->all(),
            ],
            default => [$booking->requester_user_id],
        };

        return User::query()
            ->whereIn('id', collect($ids)->filter()->map(fn ($id) => (int) $id)->unique()->all())
            ->get()
            ->map(fn (User $user): int => (int) $user->canonical()->id)
            ->unique()
            ->values()
            ->all();
    }
}
