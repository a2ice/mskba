<?php

namespace App\Modules\VenueBooking\Application\Queries;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPartyRole;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversation;
use Carbon\CarbonInterface;

final class GetRentalReviewTimeline
{
    /** @return list<array{label: string, at: CarbonInterface}> */
    public function handle(VenueBooking $booking, User $viewer): array
    {
        $identityIds = $viewer->canonical()->identityIds();
        if (! in_array((int) $booking->requester_user_id, $identityIds, true)) {
            return [];
        }

        $rows = [];
        $add = static function (string $label, ?CarbonInterface $at) use (&$rows): void {
            if ($at !== null) $rows[] = ['label' => $label, 'at' => $at];
        };
        $seen = [];
        foreach ($booking->transitions()->get() as $transition) {
            $status = $transition->to_status;
            $seen[$status->value] = true;
            $add($status->label(), $transition->created_at);
        }
        if (! isset($seen[VenueBookingStatusEnum::REQUESTED->value])) {
            $add('Заявка отправлена', $booking->requested_at);
        }
        if (! isset($seen[VenueBookingStatusEnum::HELD->value])) {
            $add('Слот удерживается', $booking->held_at);
        }
        if (! isset($seen[VenueBookingStatusEnum::CONFIRMED->value])) {
            $add('Подтверждено', $booking->confirmed_at);
        }
        if ($booking->status->isTerminal() && ! isset($seen[$booking->status->value])) {
            $add($booking->status->label(), $booking->terminal_at);
        }

        // Read receipts certify conversation activity, not inspection of the booking.
        // Only identified venue representatives are included, never internal staff
        // or other viewers. Ignore older activity in a shared pre-request thread.
        $representatives = $booking->parties()->where('role', VenueBookingPartyRole::VENUE_REPRESENTATIVE->value)
            ->whereNotIn('user_id', $identityIds)->pluck('user_id');
        if ($representatives->isNotEmpty() && $booking->requested_at !== null) {
            $conversation = VenueBookingConversation::query()->where($booking->conversationLookup())->first();
            if ($conversation !== null) {
                $firstBookingMessage = $conversation->messages()->where('created_at', '>=', $booking->requested_at)->min('id');
                if ($firstBookingMessage !== null) {
                    $read = $conversation->readMarkers()->whereIn('user_id', $representatives)
                        ->where('last_read_message_id', '>=', $firstBookingMessage)
                        ->where('read_at', '>=', $booking->requested_at)->latest('read_at')->first();
                    $add('Переписка прочитана представителем (последняя отметка)', $read?->read_at);
                }
                $reply = $conversation->messages()->where('created_at', '>=', $booking->requested_at)
                    ->whereIn('type', ['text', 'attachment'])
                    ->whereHas('authorActor', fn ($query) => $query->whereIn('user_id', $representatives))
                    ->latest('created_at')->latest('id')->first();
                $add('Последний ответ представителя в переписке', $reply?->created_at);
            }
        }
        usort($rows, static fn (array $a, array $b): int => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

        return $rows;
    }
}
