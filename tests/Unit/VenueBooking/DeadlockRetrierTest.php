<?php

namespace Tests\Unit\VenueBooking;

use App\Modules\VenueBooking\Application\Services\DeadlockRetrier;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Tests\TestCase;

final class DeadlockRetrierTest extends TestCase
{
    public function test_only_concurrency_failures_are_retried_with_a_limit(): void
    {
        $attempts = 0;
        $result = app(DeadlockRetrier::class)->run(function () use (&$attempts): string {
            $attempts++;
            if ($attempts < 3) {
                $previous = new PDOException('deadlock');
                $previous->errorInfo = ['40001', 1213, 'deadlock'];
                throw new QueryException('test', 'UPDATE venue_bookings', [], $previous);
            }

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(3, $attempts);
    }

    public function test_business_exceptions_are_not_retried(): void
    {
        $attempts = 0;

        try {
            app(DeadlockRetrier::class)->run(function () use (&$attempts): void {
                $attempts++;
                throw new RuntimeException('business failure');
            });
            $this->fail('Expected business exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('business failure', $exception->getMessage());
        }
        $this->assertSame(1, $attempts);
    }
}
