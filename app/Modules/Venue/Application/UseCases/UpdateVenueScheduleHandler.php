<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Exceptions\VenueAccessDeniedException;
use App\Modules\Venue\Domain\Exceptions\VenueNotFoundException;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;

final class UpdateVenueScheduleHandler
{
    public function __construct(
        private readonly VenueAccessResolver $access,
    ) {}

    /**
     * @param  array<int, array<int, array{starts_at: string, ends_at: string}>>  $intervalsByDay
     */
    public function handle(string $alias, User $user, string $timezone, array $intervalsByDay): Venue
    {
        return DB::transaction(function () use ($alias, $user, $timezone, $intervalsByDay): Venue {
            $venue = Venue::query()
                ->whereRouteIdentifier($alias)
                ->lockForUpdate()
                ->first();

            if ($venue === null) {
                throw new VenueNotFoundException;
            }

            if (! $this->access->canEditSchedule($user, $venue)) {
                throw new VenueAccessDeniedException;
            }

            $schedule = $venue->schedule()->updateOrCreate(
                ['venue_id' => $venue->id],
                ['timezone' => $timezone],
            );

            $schedule->intervals()->delete();

            foreach ($intervalsByDay as $dayOfWeek => $intervals) {
                foreach (array_values($intervals) as $sortOrder => $interval) {
                    $schedule->intervals()->create([
                        'day_of_week' => $dayOfWeek,
                        'starts_at' => $interval['starts_at'],
                        'ends_at' => $interval['ends_at'],
                        'sort_order' => $sortOrder,
                    ]);
                }
            }

            return $venue->refresh()->load('schedule.intervals');
        });
    }
}
