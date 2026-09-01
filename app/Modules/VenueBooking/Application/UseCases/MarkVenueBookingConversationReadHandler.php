<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Domain\Events\VenueBookingConversationRead as ConversationReadEvent;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversation;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversationRead;
use App\Modules\VenueBooking\Domain\Models\VenueBookingMessage;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\DB;

final readonly class MarkVenueBookingConversationReadHandler
{
    public function __construct(private LockedVenueBooking $lockedBooking, private VenueBookingAuthorization $authorization, private FeatureFlags $features) {}

    public function handle(int $bookingId, int $conversationId, Actor $actor, ?int $messageId): VenueBookingConversationRead
    {
        $this->features->ensureEnabled(VenueRentalFeature::CONVERSATIONS);

        return $this->lockedBooking->run($bookingId, function (VenueBooking $booking, $venue) use ($conversationId, $actor, $messageId): VenueBookingConversationRead {
            $this->authorization->assertCanView($actor, $booking, $venue);
            if ($actor->user_id === null) {
                throw new VenueBookingTransitionException('Для отметки прочтения нужен пользователь.', 'BOOKING_FORBIDDEN');
            }
            $conversation = VenueBookingConversation::query()->lockForUpdate()->findOrFail($conversationId);
            if ($conversation->venue_booking_id !== $booking->id) {
                throw new VenueBookingTransitionException('Переписка не относится к этой брони.', 'CONVERSATION_BOOKING_MISMATCH');
            }
            $message = $messageId === null ? null : VenueBookingMessage::query()->where('conversation_id', $conversation->id)->findOrFail($messageId);
            $marker = VenueBookingConversationRead::query()->where('conversation_id', $conversation->id)->where('user_id', $actor->user_id)->lockForUpdate()->first();
            if ($marker !== null && ($marker->last_read_message_id ?? 0) >= ($message?->id ?? 0)) {
                return $marker;
            }
            $marker ??= new VenueBookingConversationRead(['conversation_id' => $conversation->id, 'user_id' => $actor->user_id]);
            $marker->fill(['last_read_message_id' => $message?->id, 'read_at' => now()])->save();
            DB::afterCommit(static fn () => event(new ConversationReadEvent($booking->id, $conversation->id, $actor->user_id, $message?->id)));

            return $marker;
        });
    }
}
