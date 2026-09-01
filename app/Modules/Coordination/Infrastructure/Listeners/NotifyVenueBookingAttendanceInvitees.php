<?php

namespace App\Modules\Coordination\Infrastructure\Listeners;

use App\Modules\Coordination\Domain\Events\VenueBookingAttendanceRoundOpened;
use App\Modules\Coordination\Domain\Models\VenueBookingAttendanceRound;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationSourceEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class NotifyVenueBookingAttendanceInvitees
{
    public function __construct(
        private CreateUserNotificationHandler $notifications,
        private FeatureFlags $features,
    ) {}

    public function handle(VenueBookingAttendanceRoundOpened $event): void
    {
        if (! $this->features->enabled(VenueRentalFeature::ATTENDANCE_V2)) {
            return;
        }
        $round = VenueBookingAttendanceRound::query()->with(['booking.venue', 'responses'])->find($event->roundId);
        if ($round === null) {
            return;
        }

        foreach ($round->responses as $response) {
            $this->notifications->handle(new CreateUserNotificationDTO(
                userId: $response->user_id,
                type: UserNotificationTypeEnum::REMINDER,
                title: 'Подтвердите участие',
                body: 'Организатор уточняет явку на '.$round->booking->venue->name.'. Ответ не продлевает удержание площадки.',
                actionUrl: route('venue-booking-attendance.show', $round, false),
                actionText: 'Ответить',
                payload: [
                    'source' => UserNotificationSourceEnum::VENUE_BOOKING_ATTENDANCE_OPENED->value,
                    'round_id' => $round->public_id,
                    'booking_id' => $round->booking->public_id,
                ],
            ));
        }
    }
}
