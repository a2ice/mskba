<?php

namespace App\Modules\Coordination\Application\UseCases;

use App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus;
use App\Modules\Coordination\Domain\Events\VenueRentalCoordinationClosed;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\Identity\Domain\Models\Actor;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class CloseVenueRentalCoordinationHandler
{
    public function __construct(private FeatureFlags $features) {}

    public function handle(int $id, Actor $actor): VenueRentalCoordination
    {
        $this->features->ensureEnabled(VenueRentalFeature::COORDINATION);

        return DB::transaction(function () use ($id, $actor): VenueRentalCoordination {
            $coordination = VenueRentalCoordination::query()->lockForUpdate()->findOrFail($id);
            if ($coordination->organizer_actor_id !== $actor->id) {
                throw new InvalidArgumentException('Закрыть сбор может только организатор.');
            }
            if ($coordination->status === VenueRentalCoordinationStatus::OPEN) {
                $coordination->update(['status' => VenueRentalCoordinationStatus::CLOSED, 'closed_at' => now()]);
                DB::afterCommit(static fn () => event(new VenueRentalCoordinationClosed($coordination->id)));
            }

            return $coordination;
        });
    }
}
