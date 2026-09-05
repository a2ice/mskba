<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOwnershipStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnership;
use App\Modules\Venue\Domain\Events\VenueOwnershipStatusChanged;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class UpdateVenueOwnershipStatusHandler
{
    public function handle(
        VenueOwnership $ownership,
        VenueOwnershipStatusEnum $status,
        string $reason,
        User $administrator,
    ): VenueOwnership {
        $administrator = $administrator->canonical();
        abort_unless(
            $administrator->isConfirmed() && $administrator->system_role->atLeast(UserSystemRoleEnum::ADMIN),
            403,
        );

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Укажите причину изменения статуса владения.');
        }

        return DB::transaction(function () use ($ownership, $status, $reason, $administrator): VenueOwnership {
            Venue::query()->lockForUpdate()->findOrFail($ownership->venue_id);
            $ownership = VenueOwnership::query()
                ->with('contractMembership.contract')
                ->lockForUpdate()
                ->findOrFail($ownership->id);

            if ($ownership->status === VenueOwnershipStatusEnum::REVOKED && $status !== VenueOwnershipStatusEnum::REVOKED) {
                throw new InvalidArgumentException('Аннулированное владение нельзя восстановить. Создайте новое подтверждение управления.');
            }

            if ($ownership->status === $status) {
                $ownership->forceFill([
                    'status_reason' => $reason,
                    'status_changed_by_user_id' => $administrator->id,
                    'status_changed_at' => now(),
                ])->save();

                return $ownership->refresh();
            }

            $contract = $ownership->contractMembership->contract;
            $now = now();

            if ($status === VenueOwnershipStatusEnum::ACTIVE) {
                $contract->forceFill(['status' => ContractStatusEnum::ACTIVE])->save();
                $ownership->forceFill([
                    'status' => $status,
                    'status_reason' => $reason,
                    'status_changed_by_user_id' => $administrator->id,
                    'status_changed_at' => $now,
                    'revoked_at' => null,
                    'active_marker' => true,
                ])->save();
            } elseif ($status === VenueOwnershipStatusEnum::UNDER_REVIEW) {
                $contract->forceFill(['status' => ContractStatusEnum::INACTIVE])->save();
                $ownership->forceFill([
                    'status' => $status,
                    'status_reason' => $reason,
                    'status_changed_by_user_id' => $administrator->id,
                    'status_changed_at' => $now,
                    'revoked_at' => null,
                    'active_marker' => true,
                ])->save();
            } else {
                $contract->forceFill(['status' => ContractStatusEnum::INACTIVE])->save();
                $ownership->forceFill([
                    'status' => VenueOwnershipStatusEnum::REVOKED,
                    'status_reason' => $reason,
                    'status_changed_by_user_id' => $administrator->id,
                    'status_changed_at' => $now,
                    'revoked_at' => $now,
                    'active_marker' => null,
                ])->save();
            }

            $ownership = $ownership->refresh();
            DB::afterCommit(static fn () => event(new VenueOwnershipStatusChanged($ownership->id)));

            return $ownership;
        });
    }
}
