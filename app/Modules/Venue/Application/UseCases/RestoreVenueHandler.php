<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;

final class RestoreVenueHandler
{
    public function handle(int $venueId): void
    {
        DB::transaction(function () use ($venueId): void {
            Venue::query()
                ->onlyTrashed()
                ->whereKey($venueId)
                ->lockForUpdate()
                ->firstOrFail()
                ->restore();
        });
    }
}
