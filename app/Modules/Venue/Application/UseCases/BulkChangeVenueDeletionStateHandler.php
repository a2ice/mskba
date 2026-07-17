<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;

final class BulkChangeVenueDeletionStateHandler
{
    /**
     * @param  array<int>  $venueIds
     */
    public function delete(array $venueIds): int
    {
        return DB::transaction(function () use ($venueIds): int {
            $venues = Venue::query()
                ->whereKey($venueIds)
                ->lockForUpdate()
                ->get();

            $venues->each->delete();

            return $venues->count();
        });
    }

    /**
     * @param  array<int>  $venueIds
     */
    public function restore(array $venueIds): int
    {
        return DB::transaction(function () use ($venueIds): int {
            $venues = Venue::query()
                ->onlyTrashed()
                ->whereKey($venueIds)
                ->lockForUpdate()
                ->get();

            $venues->each->restore();

            return $venues->count();
        });
    }
}
