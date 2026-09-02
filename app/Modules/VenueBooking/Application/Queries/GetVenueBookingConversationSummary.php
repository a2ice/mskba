<?php

namespace App\Modules\VenueBooking\Application\Queries;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversation;
use Illuminate\Database\Eloquent\Builder;

final readonly class GetVenueBookingConversationSummary
{
    /** @return array{conversation_id: string|null, unread_count: int, latest_message_id: string|null} */
    public function handle(VenueBooking $booking, Actor $actor): array
    {
        $conversation = VenueBookingConversation::query()
            ->with('readMarkers')
            ->where('venue_booking_id', $booking->id)
            ->first();

        if ($conversation === null) {
            return ['conversation_id' => null, 'unread_count' => 0, 'latest_message_id' => null];
        }

        $userId = $actor->user?->canonical()->id;
        $lastReadId = $userId === null
            ? 0
            : (int) ($conversation->readMarkers->firstWhere('user_id', $userId)?->last_read_message_id ?? 0);
        $unreadCount = $conversation->messages()
            ->where('id', '>', $lastReadId)
            ->whereHas('authorActor', static function (Builder $query) use ($userId): void {
                $query->when(
                    $userId === null,
                    static fn (Builder $query): Builder => $query->whereNotNull('user_id'),
                    static fn (Builder $query): Builder => $query->where(static fn (Builder $query): Builder => $query
                        ->whereNull('user_id')
                        ->orWhere('user_id', '!=', $userId)),
                );
            })
            ->count();

        return [
            'conversation_id' => $conversation->public_id,
            'unread_count' => $unreadCount,
            'latest_message_id' => $conversation->messages()->latest('id')->value('public_id'),
        ];
    }
}
