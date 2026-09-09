<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOwnershipStatusEnum;
use App\Modules\Venue\Domain\Models\VenueOwnership;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class UpdateVenueOwnershipMaintenanceHandler
{
    public function handle(
        VenueOwnership $ownership,
        bool $commitmentAccepted,
        int $score,
        ?string $comment,
        User $administrator,
    ): VenueOwnership {
        $administrator = $administrator->canonical();
        abort_unless(
            $administrator->isConfirmed() && $administrator->system_role->atLeast(UserSystemRoleEnum::ADMIN),
            403,
        );

        if (! in_array($score, VenueOwnership::MAINTENANCE_SCORES, true)) {
            throw new InvalidArgumentException('Оценка качества должна быть от 0 до 100 с шагом 10.');
        }

        $comment = $comment === null ? null : trim($comment);
        $comment = $comment === '' ? null : $comment;

        return DB::transaction(function () use ($ownership, $commitmentAccepted, $score, $comment): VenueOwnership {
            $ownership = VenueOwnership::query()->lockForUpdate()->findOrFail($ownership->id);

            if ($ownership->status === VenueOwnershipStatusEnum::REVOKED) {
                throw new InvalidArgumentException('Качество аннулированного владения нельзя изменять.');
            }

            $ownership->forceFill([
                'maintenance_commitment_accepted' => $commitmentAccepted,
                'maintenance_score' => $score,
                'maintenance_comment' => $comment,
            ])->save();

            return $ownership->refresh();
        });
    }
}
