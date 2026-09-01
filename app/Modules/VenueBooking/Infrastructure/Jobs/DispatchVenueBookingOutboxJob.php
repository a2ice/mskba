<?php

namespace App\Modules\VenueBooking\Infrastructure\Jobs;

use App\Modules\VenueBooking\Application\Services\VenueBookingOutboxDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DispatchVenueBookingOutboxJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    /** @var list<int> */
    public array $backoff = [2, 5, 15, 30, 60, 300, 900];

    public function __construct(public readonly int $messageId) {}

    public function handle(VenueBookingOutboxDispatcher $dispatcher): void
    {
        $dispatcher->dispatch($this->messageId);
    }
}
