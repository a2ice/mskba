<?php

namespace App\Modules\VenueBooking\Application\Services;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class DeadlockRetrier
{
    /** @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback, int $maximumAttempts = 3): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $callback();
            } catch (QueryException $exception) {
                if ($attempt >= $maximumAttempts || ! $this->isConcurrencyFailure($exception)) {
                    throw $exception;
                }

                try {
                    Cache::increment('metrics:venue_booking:deadlock_retry');
                } catch (\Throwable) {
                    // Metrics degradation must not mask a retryable database failure.
                }
                try {
                    Log::warning('venue_booking_deadlock_retry', ['attempt' => $attempt, 'sql_state' => $exception->errorInfo[0] ?? null]);
                } catch (\Throwable) {
                }
                usleep(random_int(10_000, 40_000) * $attempt);
            }
        }
    }

    private function isConcurrencyFailure(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array($sqlState, ['40001', '40P01'], true)
            || in_array($driverCode, [1205, 1213], true);
    }
}
