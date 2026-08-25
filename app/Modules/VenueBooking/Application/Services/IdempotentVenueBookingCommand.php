<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingIdempotencyException;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingCommandReceipt;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class IdempotentVenueBookingCommand
{
    public function __construct(private VenueBookingCommandContext $context) {}

    /** @param array<string, mixed> $payload
     * @param  Closure(): VenueBooking  $callback
     */
    public function execute(
        string $commandName,
        Actor $actor,
        array $payload,
        Closure $callback,
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
    ): VenueBooking {
        $idempotencyKey ??= (string) Str::uuid();
        $correlationId ??= $idempotencyKey;
        $payloadHash = hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $commandName,
            $actor,
            $payloadHash,
            $callback,
            $idempotencyKey,
            $correlationId,
        ): VenueBooking {
            $now = now();
            VenueBookingCommandReceipt::query()->insertOrIgnore([
                'actor_id' => $actor->id,
                'command_name' => $commandName,
                'idempotency_key' => $idempotencyKey,
                'correlation_id' => $correlationId,
                'payload_hash' => $payloadHash,
                'status' => 'processing',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $receipt = VenueBookingCommandReceipt::query()
                ->where('actor_id', $actor->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->firstOrFail();

            if ($receipt->command_name !== $commandName || $receipt->payload_hash !== $payloadHash) {
                throw new VenueBookingIdempotencyException;
            }

            if ($receipt->status === 'completed') {
                return VenueBooking::query()->findOrFail($receipt->venue_booking_id);
            }

            if ($receipt->status !== 'processing') {
                throw new VenueBookingTransitionException('Команда временно недоступна.', 'COMMAND_UNAVAILABLE');
            }

            $this->context->enter($receipt->id, $receipt->correlation_id);
            try {
                $booking = $callback();
            } finally {
                $this->context->leave();
            }

            $receipt->update([
                'venue_booking_id' => $booking->id,
                'status' => 'completed',
                'response' => [
                    'booking_id' => $booking->public_id,
                    'status' => $booking->status->value,
                    'version' => $booking->optimistic_version,
                ],
            ]);

            return $booking;
        });
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
