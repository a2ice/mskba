<?php

namespace App\Modules\VenueBooking\Infrastructure\Listeners;

use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\VenueBooking\Domain\Events\VenueBookingMessageSent;
use App\Modules\VenueBooking\Domain\Models\VenueBookingMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

final class NotifyVenueBookingMessageRecipients implements ShouldQueue
{
    public function handle(VenueBookingMessageSent $event): void
    {
        $message = VenueBookingMessage::query()->with(['authorActor', 'conversation.booking.parties'])->find($event->messageId);
        if ($message === null) {
            return;
        }
        $booking = $message->conversation->booking;
        $recipients = collect([$booking->requester_user_id])
            ->merge($booking->parties->pluck('user_id'))
            ->filter()->unique()->reject(fn (int $userId): bool => $userId === $message->authorActor->user_id);

        foreach ($recipients as $userId) {
            DB::transaction(function () use ($message, $booking, $userId): void {
                $inserted = DB::table('venue_booking_message_deliveries')->insertOrIgnore([
                    'message_id' => $message->id, 'recipient_user_id' => $userId,
                    'channel' => 'in_app', 'status' => 'processing', 'attempts' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($inserted === 0) {
                    return;
                }
                app(CreateUserNotificationHandler::class)->handle(new CreateUserNotificationDTO(
                    userId: (int) $userId,
                    type: UserNotificationTypeEnum::SYSTEM,
                    title: 'Новое сообщение по аренде',
                    body: 'В переписке по заявке появилось новое сообщение.',
                    actionUrl: route('account.venue-bookings.show', $booking),
                    actionText: 'Открыть переписку',
                    payload: ['booking_id' => $booking->public_id, 'message_id' => $message->public_id],
                ));
                DB::table('venue_booking_message_deliveries')->where([
                    'message_id' => $message->id, 'recipient_user_id' => $userId, 'channel' => 'in_app',
                ])->update(['status' => 'delivered', 'delivered_at' => now(), 'updated_at' => now()]);
            });
        }
    }
}
