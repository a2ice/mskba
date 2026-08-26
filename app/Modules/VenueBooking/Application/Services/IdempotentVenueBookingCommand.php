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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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

        try {
            $booking = DB::transaction(function () use (
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
        } catch (Throwable $exception) {
            $errorCode = $exception instanceof VenueBookingTransitionException ? $exception->errorCode : class_basename($exception);
            $this->incrementMetric('metrics:venue_booking:command:failed:'.$commandName);
            $this->log('warning', 'venue_booking_command_failed', [
                'command' => $commandName, 'correlation_id' => $correlationId,
                'outcome' => 'failed', 'error_code' => $errorCode,
            ]);
            throw $exception;
        }

        $this->incrementMetric('metrics:venue_booking:command:succeeded:'.$commandName);
        $this->log('info', 'venue_booking_command_completed', [
            'command' => $commandName, 'booking_id' => $booking->id,
            'correlation_id' => $correlationId, 'outcome' => 'succeeded',
            'status' => $booking->status->value, 'version' => $booking->optimistic_version,
        ]);

        return $booking;
    }

    private function incrementMetric(string $key): void
    {
        try {
            Cache::increment($key);
        } catch (Throwable) {
            // The committed command outcome must not depend on metrics availability.
        }
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $message, array $context): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (Throwable) {
        }
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
