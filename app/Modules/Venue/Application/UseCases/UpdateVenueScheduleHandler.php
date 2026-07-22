<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
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
     * @param  array<int, array{date: string, is_closed: bool, intervals: array<int, array{starts_at: string, ends_at: string}>}>  $exceptions
     */
    public function handle(
        string $alias,
        User $user,
        string $timezone,
        array $intervalsByDay,
        array $exceptions = [],
        ?VenueOperationalStatusEnum $operationalStatus = null,
    ): Venue {
        return DB::transaction(function () use ($alias, $user, $timezone, $intervalsByDay, $exceptions, $operationalStatus): Venue {
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

            if ($operationalStatus !== null) {
                $venue->forceFill(['operational_status' => $operationalStatus])->save();
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

            $schedule->exceptions()->delete();
            foreach ($exceptions as $exceptionData) {
                $exception = $schedule->exceptions()->create([
                    'date' => $exceptionData['date'],
                    'is_closed' => $exceptionData['is_closed'],
                ]);

                foreach (array_values($exceptionData['intervals']) as $sortOrder => $interval) {
                    $exception->intervals()->create([
                        'starts_at' => $interval['starts_at'],
                        'ends_at' => $interval['ends_at'],
                        'sort_order' => $sortOrder,
                    ]);
                }
            }

            return $venue->refresh()->load('schedule.intervals', 'schedule.exceptions.intervals');
        });
    }
}
