<?php

namespace App\Modules\Dashboard\Infrastructure\Observers;

use App\Modules\Dashboard\Application\Services\DashboardInvalidator;
use App\Modules\Event\Domain\Models\EventParticipant;
use App\Modules\Event\Domain\Models\GameAdmission;
use App\Modules\Finance\Domain\Models\Wallet;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\PlayInterest\Domain\Models\VenueSubscription;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueRentalPreRequest;
use Illuminate\Database\Eloquent\Model;

final class DashboardPersonalDataObserver
{
    public function created(Model $model): void
    {
        $this->invalidate($model, false);
    }

    public function updated(Model $model): void
    {
        $this->invalidate($model, true);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model, false);
    }

    private function invalidate(Model $model, bool $includePrevious): void
    {
        [$source, $ownerField] = match (true) {
            $model instanceof Wallet => ['wallet_summary', 'owner_id'],
            $model instanceof PlayIntent, $model instanceof VenueSubscription => ['my_interests', 'user_id'],
            $model instanceof EventParticipant, $model instanceof GameAdmission => ['upcoming_events', 'user_id'],
            $model instanceof VenueBooking, $model instanceof VenueRentalPreRequest => ['my_rental_requests', 'requester_user_id'],
            default => [null, null],
        };
        if ($source === null) {
            return;
        }

        if ($includePrevious && array_diff(array_keys($model->getChanges()), ['updated_at', 'created_at']) === []) {
            return;
        }

        $current = $model->getAttributes();
        $previous = $model->getRawOriginal();
        $eligible = static function (array $attributes) use ($model): bool {
            if ($model instanceof Wallet) {
                return ($attributes['owner_type'] ?? null) === 'user'
                    && ($attributes['type'] ?? null) === 'main'
                    && ($attributes['currency'] ?? null) === 'RUB';
            }
            if ($model instanceof VenueBooking) {
                return ($attributes['flow'] ?? null) === 'rental';
            }

            return true;
        };

        $ids = [];
        if ($eligible($current)) {
            $ids[] = $current[$ownerField] ?? null;
        }
        if ($includePrevious && $eligible($previous)) {
            $ids[] = $previous[$ownerField] ?? null;
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return;
        }

        // Capture scalar identifiers before the model changes again. Rollbacks
        // discard this callback; no business record is carried on the socket.
        $model->getConnection()->afterCommit(static function () use ($ids, $source): void {
            try {
                app(DashboardInvalidator::class)->send($ids, [$source]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}
