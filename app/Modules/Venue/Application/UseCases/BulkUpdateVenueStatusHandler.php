<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class BulkUpdateVenueStatusHandler
{
    /**
     * @param  array<int>  $venueIds
     */
    public function handle(array $venueIds, VenueStatusEnum $status, ?string $message = null): int
    {
        if (! in_array($status, [VenueStatusEnum::BLOCKED, VenueStatusEnum::UNCONFIRMED], true)) {
            throw new InvalidArgumentException('Недопустимый массовый статус площадки.');
        }

        return DB::transaction(function () use ($venueIds, $status, $message): int {
            $venues = Venue::query()
                ->whereKey($venueIds)
                ->lockForUpdate()
                ->get();

            if ($status === VenueStatusEnum::UNCONFIRMED && $venues->contains(
                fn (Venue $venue): bool => $venue->status !== VenueStatusEnum::BLOCKED
            )) {
                throw new InvalidArgumentException('Снять блокировку можно только у заблокированных площадок.');
            }

            if ($status === VenueStatusEnum::BLOCKED && trim((string) $message) === '') {
                throw new InvalidArgumentException('Укажите причину блокировки.');
            }

            $venues->each(function (Venue $venue) use ($status, $message): void {
                $venue->forceFill([
                    'status' => $status,
                    'status_info' => $status === VenueStatusEnum::BLOCKED ? trim((string) $message) : null,
                ])->save();
            });

            return $venues->count();
        });
    }
}
