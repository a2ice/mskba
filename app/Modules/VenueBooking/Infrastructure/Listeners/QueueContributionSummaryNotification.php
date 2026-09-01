<?php

namespace App\Modules\VenueBooking\Infrastructure\Listeners;

use App\Modules\VenueBooking\Domain\Events\ContributionCommitmentSet;
use App\Modules\VenueBooking\Domain\Events\ContributionCommitmentWithdrawn;
use App\Modules\VenueBooking\Infrastructure\Jobs\NotifyContributionSummaryJob;

final class QueueContributionSummaryNotification
{
    public function handle(ContributionCommitmentSet|ContributionCommitmentWithdrawn $event): void
    {
        NotifyContributionSummaryJob::dispatch($event->bookingId)->delay(now()->addMinutes(2));
    }
}
