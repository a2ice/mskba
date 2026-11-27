<?php

namespace App\Modules\VenueIntegration\Application\Jobs;

use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueIntegration\Application\Events\VisitedCourtScheduleRefreshed;
use App\Modules\VenueIntegration\Application\Services\FindSportOnDemandRefresh;
use App\Modules\VenueIntegration\Application\Services\VisitScheduleRefresh;
use App\Modules\VenueIntegration\Domain\Models\VenueCourtExternalScheduleImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RefreshVisitedCourtSchedule implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $importId,
        public readonly int $sourceId,
        public readonly string $token,
    ) {
        $this->onConnection('redis')->onQueue('venue-schedule-refresh');
    }

    public function handle(VisitScheduleRefresh $visits, FindSportOnDemandRefresh $refresh): void
    {
        $key = VisitScheduleRefresh::pendingKey($this->sourceId);
        if (Cache::get($key) !== $this->token) {
            return;
        }

        $import = VenueCourtExternalScheduleImport::query()
            ->with(['source.integration', 'snapshot', 'court'])->find($this->importId);
        $state = 'disabled';
        try {
            if ($import === null || (int) $import->venue_integration_source_id !== $this->sourceId
                || ! Venue::query()->find($import->venue_id)?->isPubliclyVisible()
                || ! $visits->eligible($import)) {
                return;
            }

            $state = $refresh->forCourt($import->court)['state'];
        } finally {
            // A simultaneous day-modal refresh may own the shared source lock.
            // Keep the pending marker until its bounded expiry in that case.
            if ($state !== 'updating' && Cache::get($key) === $this->token) {
                Cache::forget($key);
            }

            if ($import !== null && $state !== 'disabled') {
                try {
                    event(new VisitedCourtScheduleRefreshed((int) $import->venue_id));
                } catch (Throwable $exception) {
                    // The snapshot is committed even if Reverb is unavailable.
                    report($exception);
                }
            }
        }
    }
}
