<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdateVenueStatusHandler
{
    public function handle(Venue $venue, VenueStatusEnum $status, ?string $statusInfo = null): Venue
    {
        return DB::transaction(function () use ($venue, $status, $statusInfo): Venue {
            if ($status === VenueStatusEnum::CONFIRMED) {
                throw new InvalidArgumentException('Подтвердить площадку можно только через одобрение заявки модерации.');
            }

            $venue = Venue::query()
                ->whereKey($venue->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($venue->status === VenueStatusEnum::CONFIRMED && $status === VenueStatusEnum::UNCONFIRMED) {
                throw new InvalidArgumentException('Подтвержденную площадку нельзя вернуть в статус "не подтверждена".');
            }

            $changes = ['status' => $status];
            // The comment belongs to the administrative history, not only
            // to a blocked status. Preserve it if the caller omitted a note.
            if ($statusInfo !== null && (trim($statusInfo) !== ''
                || $status === VenueStatusEnum::BLOCKED)) {
                $changes['status_info'] = trim($statusInfo) ?: null;
            }
            $venue->forceFill($changes)->save();

            return $venue->refresh();
        });
    }
}
