<?php

namespace App\Modules\Venue\Infrastructure\Listeners;

use App\Modules\Moderation\Domain\Enums\ModerationTypeEnum;
use App\Modules\Moderation\Domain\Events\ModerationRequestApproved;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;

final class ConfirmVenueAfterModerationRequestApproved
{
    public function handle(ModerationRequestApproved $event): void
    {
        if ($event->request->type !== ModerationTypeEnum::VENUE) {
            return;
        }

        DB::transaction(function () use ($event): void {
            $venue = Venue::query()
                ->whereKey($event->request->subject_id)
                ->lockForUpdate()
                ->first();

            if ($venue === null) {
                return;
            }

            if ($venue->status === VenueStatusEnum::CONFIRMED && $venue->status_info === null) {
                return;
            }

            $venue->forceFill([
                'status' => VenueStatusEnum::CONFIRMED,
                'status_info' => null,
            ])->save();
        });
    }
}
