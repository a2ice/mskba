<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;

final class DeleteVenueHandler
{
    public function handle(Venue $venue): void
    {
        DB::transaction(function () use ($venue): void {
            Venue::query()
                ->whereKey($venue->id)
                ->lockForUpdate()
                ->firstOrFail()
                ->delete();
        });
    }
}
