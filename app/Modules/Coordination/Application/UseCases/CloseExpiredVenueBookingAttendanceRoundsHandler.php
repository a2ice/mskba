<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Domain\Enums\VenueBookingAttendanceRoundStatus;
use App\Modules\Coordination\Domain\Models\VenueBookingAttendanceRound;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class CloseExpiredVenueBookingAttendanceRoundsHandler
{
    public function __construct(
        private FeatureFlags $features,
        private CurrentActorResolver $actors,
        private CloseVenueBookingAttendanceRoundHandler $close,
    ) {}

    public function handle(): int
    {
        if (! $this->features->enabled(VenueRentalFeature::ATTENDANCE_V2)) {
            return 0;
        }
        $closed = 0;
        $system = $this->actors->system();
        VenueBookingAttendanceRound::query()
            ->where('status', VenueBookingAttendanceRoundStatus::OPEN)
            ->where('deadline_at', '<=', now())
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($roundId) use (&$closed, $system): void {
                $round = $this->close->handle((int) $roundId, $system, 'deadline');
                if ($round->wasChanged('status')) {
                    $closed++;
                }
            });

        return $closed;
    }
}
