<?php

namespace App\Modules\VenueBooking\Infrastructure\Jobs;

use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\VenueBooking\Domain\Enums\BookingContributionStatus;
use App\Modules\VenueBooking\Domain\Models\BookingContributionCommitment;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class NotifyContributionSummaryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 180;

    public function __construct(public readonly int $bookingId) {}

    public function uniqueId(): string
    {
        return (string) $this->bookingId;
    }

    public function handle(CreateUserNotificationHandler $notifications): void
    {
        $booking = VenueBooking::query()->find($this->bookingId);
        if ($booking === null) {
            return;
        }

        $total = (int) BookingContributionCommitment::query()
            ->where('venue_booking_id', $booking->id)
            ->where('status', BookingContributionStatus::ACTIVE)
            ->sum('amount_minor');
        $notifications->handle(new CreateUserNotificationDTO(
            userId: $booking->requester_user_id,
            type: UserNotificationTypeEnum::SYSTEM,
            title: 'Обновился сбор на аренду',
            body: 'Общая сумма обещаний участников изменилась.',
            actionUrl: route('account.venue-bookings.show', $booking),
            actionText: 'Открыть сбор',
            payload: ['booking_id' => $booking->public_id, 'committed_minor' => $total],
        ));
    }
}
