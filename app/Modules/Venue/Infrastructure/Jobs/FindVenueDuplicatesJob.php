<?php

namespace App\Modules\Venue\Infrastructure\Jobs;

use App\Modules\Venue\Application\Services\VenueDuplicateDetector;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FindVenueDuplicatesJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $venueId,
    ) {}

    public function handle(VenueDuplicateDetector $detector): void
    {
        $venue = Venue::query()->find($this->venueId);

        if ($venue === null) {
            return;
        }

        $detector->detectFor($venue);
    }
}
