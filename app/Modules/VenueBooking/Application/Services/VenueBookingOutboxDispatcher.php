<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\VenueBooking\Domain\Events\VenueBookingCancelled;
use App\Modules\VenueBooking\Domain\Events\VenueBookingConfirmed;
use App\Modules\VenueBooking\Domain\Events\VenueBookingExpired;
use App\Modules\VenueBooking\Domain\Events\VenueBookingHeld;
use App\Modules\VenueBooking\Domain\Events\VenueBookingRejected;
use App\Modules\VenueBooking\Domain\Events\VenueBookingRequested;
use App\Modules\VenueBooking\Domain\Models\VenueBookingOutboxMessage;
use Illuminate\Support\Facades\DB;
use Throwable;

final class VenueBookingOutboxDispatcher
{
    public function dispatch(int $messageId): bool
    {
        $message = DB::transaction(function () use ($messageId): ?VenueBookingOutboxMessage {
            $message = VenueBookingOutboxMessage::query()->lockForUpdate()->find($messageId);

            if ($message === null || $message->status !== 'pending' || $message->available_at->isFuture()) {
                return null;
            }

            $message->update([
                'status' => 'processing',
                'processing_started_at' => now(),
                'attempts' => $message->attempts + 1,
            ]);

            return $message;
        });

        if ($message === null) {
            return false;
        }

        try {
            $eventClass = $message->event_type;
            if (! in_array($eventClass, $this->allowedEventTypes(), true)) {
                throw new \UnexpectedValueException('Unsupported venue booking outbox event type.');
            }
            event(new $eventClass(
                bookingId: (int) $message->payload['booking_id'],
                messageId: $message->message_id,
            ));
            $message->update([
                'status' => 'published',
                'processing_started_at' => null,
                'published_at' => now(),
                'last_error' => null,
            ]);

            return true;
        } catch (Throwable $exception) {
            $delaySeconds = min(3600, 2 ** min($message->attempts, 10));
            $message->update([
                'status' => 'pending',
                'processing_started_at' => null,
                'available_at' => now()->addSeconds($delaySeconds),
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            throw $exception;
        }
    }

    public function dispatchPending(int $limit = 100): int
    {
        VenueBookingOutboxMessage::query()
            ->where('status', 'processing')
            ->where('processing_started_at', '<=', now()->subMinutes(5))
            ->update([
                'status' => 'pending',
                'processing_started_at' => null,
                'available_at' => now(),
                'last_error' => 'Recovered stale outbox claim.',
            ]);

        $ids = VenueBookingOutboxMessage::query()
            ->where('status', 'pending')
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $published = 0;
        foreach ($ids as $id) {
            $published += $this->dispatch((int) $id) ? 1 : 0;
        }

        return $published;
    }

    /** @return list<class-string> */
    private function allowedEventTypes(): array
    {
        return [
            VenueBookingRequested::class,
            VenueBookingHeld::class,
            VenueBookingConfirmed::class,
            VenueBookingRejected::class,
            VenueBookingCancelled::class,
            VenueBookingExpired::class,
        ];
    }
}
