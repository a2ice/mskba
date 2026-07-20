<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Exceptions\VenueAccessDeniedException;
use App\Modules\Venue\Domain\Exceptions\VenueNotFoundException;
use App\Modules\Venue\Domain\Models\Venue;

final class ShowAccountVenueScheduleHandler
{
    public function __construct(
        private readonly VenueAccessResolver $access,
    ) {}

    public function handle(string $alias, User $user): Venue
    {
        $venue = Venue::query()
            ->with('schedule.intervals')
            ->whereRouteIdentifier($alias)
            ->first();

        if ($venue === null) {
            throw new VenueNotFoundException;
        }

        if (! $this->access->canEditSchedule($user, $venue)) {
            throw new VenueAccessDeniedException;
        }

        return $venue;
    }
}
