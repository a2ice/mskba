<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Exceptions\VenueAccessDeniedException;
use App\Modules\Venue\Domain\Exceptions\VenueNotFoundException;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueScheduleSlotPrice;
use Illuminate\Support\Facades\DB;

final class UpdateVenueScheduleHandler
{
    public function __construct(
        private readonly VenueAccessResolver $access,
    ) {}

    /**
     * @param  array<int, array<int, array{starts_at: string, ends_at: string}>>  $intervalsByDay
     * @param  array<int, array{date: string, is_closed: bool, intervals: array<int, array{starts_at: string, ends_at: string}>}>  $exceptions
     * @param  array<int, array{day_of_week: int, starts_at: string, whole_price_per_step_minor: ?int, half_price_per_step_minor: ?int}>|null  $slotPrices
     */
    public function handle(
        string $alias,
        User $user,
        string $timezone,
        array $intervalsByDay,
        array $exceptions = [],
        ?VenueOperationalStatusEnum $operationalStatus = null,
        bool $force = false,
        ?array $slotPrices = null,
    ): Venue {
        return DB::transaction(function () use ($alias, $user, $timezone, $intervalsByDay, $exceptions, $operationalStatus, $force, $slotPrices): Venue {
            $venue = Venue::query()
                ->whereRouteIdentifier($alias)
                ->lockForUpdate()
                ->first();

            if ($venue === null) {
                throw new VenueNotFoundException;
            }

            if (! $force && ! $this->access->canEditSchedule($user, $venue)) {
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

            if ($slotPrices !== null) {
                VenueScheduleSlotPrice::query()->where('venue_id', $venue->id)->delete();
                foreach ($slotPrices as $slotPrice) {
                    if ($slotPrice['whole_price_per_step_minor'] === null && $slotPrice['half_price_per_step_minor'] === null) {
                        continue;
                    }

                    VenueScheduleSlotPrice::query()->create([
                        'venue_id' => $venue->id,
                        'day_of_week' => $slotPrice['day_of_week'],
                        'starts_at' => $slotPrice['starts_at'],
                        'whole_price_per_step_minor' => $slotPrice['whole_price_per_step_minor'],
                        'half_price_per_step_minor' => $slotPrice['half_price_per_step_minor'],
                    ]);
                }
            }

            return $venue->refresh()->load('schedule.intervals', 'schedule.exceptions.intervals');
        });
    }
}
