<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\Identity\Domain\Models\User;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class LeaveVenueRentalCoordinationHandler
{
    public function __construct(private FeatureFlags $features) {}

    public function handle(int $id, User $user): VenueRentalCoordination
    {
        $this->features->ensureEnabled(VenueRentalFeature::COORDINATION);
        $user = $user->canonical();

        return DB::transaction(function () use ($id, $user): VenueRentalCoordination {
            $coordination = VenueRentalCoordination::query()->lockForUpdate()->findOrFail($id);
            if ($coordination->status !== VenueRentalCoordinationStatus::OPEN) {
                throw new InvalidArgumentException('Сбор участников уже закрыт.');
            }
            if ($coordination->organizer_user_id === $user->id) {
                throw new InvalidArgumentException('Организатор не может покинуть собственный сбор.');
            }
            $coordination->participants()->where('user_id', $user->id)->whereNull('left_at')->update(['left_at' => now()]);

            return $coordination->fresh('participants');
        });
    }
}
