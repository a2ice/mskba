<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus;
use App\Modules\Coordination\Domain\Events\VenueRentalCoordinationJoined;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\Identity\Domain\Models\User;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class JoinVenueRentalCoordinationHandler
{
    public function __construct(private FeatureFlags $features) {}

    public function handle(int $id, User $user): VenueRentalCoordination
    {
        $this->features->ensureEnabled(VenueRentalFeature::COORDINATION);
        $user = $user->canonical();
        if (! $user->isConfirmed()) {
            throw new InvalidArgumentException('Для участия нужен подтверждённый аккаунт.');
        }

        return DB::transaction(function () use ($id, $user): VenueRentalCoordination {
            $coordination = VenueRentalCoordination::query()->lockForUpdate()->findOrFail($id);
            if ($coordination->status !== VenueRentalCoordinationStatus::OPEN) {
                throw new InvalidArgumentException('Сбор участников уже закрыт.');
            }
            $participant = $coordination->participants()->firstOrCreate(
                ['user_id' => $user->id],
                ['joined_at' => now()],
            );
            $joined = $participant->wasRecentlyCreated;
            if ($participant->left_at !== null) {
                $participant->update(['joined_at' => now(), 'left_at' => null]);
                $joined = true;
            }
            if ($joined) {
                DB::afterCommit(static fn () => event(new VenueRentalCoordinationJoined($coordination->id, $user->id)));
            }

            return $coordination->fresh('participants');
        });
    }
}
