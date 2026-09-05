<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum;
use App\Modules\Venue\Domain\Exceptions\VenueOwnershipClaimException;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Modules\Venue\Infrastructure\Broadcasting\VenueOwnershipClaimUpdatedBroadcast;
use Illuminate\Support\Facades\DB;

final readonly class CancelVenueOwnershipClaimHandler
{
    public function handle(VenueOwnershipClaim $claim, User $applicant): VenueOwnershipClaim
    {
        $applicant = $applicant->canonical();

        return DB::transaction(function () use ($claim, $applicant): VenueOwnershipClaim {
            Venue::query()->lockForUpdate()->findOrFail($claim->venue_id);
            $claim = VenueOwnershipClaim::query()->lockForUpdate()->findOrFail($claim->id);

            if (! $applicant->isSameIdentity($claim->applicant_user_id)) {
                throw new VenueOwnershipClaimException('Отменить заявку может только её автор.');
            }

            if ($claim->status !== VenueOwnershipClaimStatusEnum::PENDING) {
                throw new VenueOwnershipClaimException('Завершённую заявку нельзя отменить.');
            }

            $claim->forceFill([
                'status' => VenueOwnershipClaimStatusEnum::CANCELLED,
                'active_marker' => null,
                'cancelled_at' => now(),
            ])->save();

            DB::afterCommit(fn () => broadcast(new VenueOwnershipClaimUpdatedBroadcast(
                $claim->public_id,
                $claim->status->value,
                $claim->status->label(),
            ))->toOthers());

            return $claim->refresh();
        });
    }
}
