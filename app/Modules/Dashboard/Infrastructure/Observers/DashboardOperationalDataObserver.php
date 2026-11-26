<?php

namespace App\Modules\Dashboard\Infrastructure\Observers;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Dashboard\Application\Providers\VenueInvitationsProvider;
use App\Modules\Dashboard\Application\Providers\VenueRentalInboxProvider;
use App\Modules\Dashboard\Application\Services\DashboardInvalidator;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Invitation\Domain\Models\AccountInvitation;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueRentalPreRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

final class DashboardOperationalDataObserver
{
    public function created(Model $model): void
    {
        $this->schedule($model, false);
    }

    public function updated(Model $model): void
    {
        if (array_diff(array_keys($model->getChanges()), ['updated_at', 'created_at']) !== []) {
            $this->schedule($model, true);
        }
    }

    public function deleted(Model $model): void
    {
        $this->schedule($model, false);
    }

    private function schedule(Model $model, bool $includePrevious): void
    {
        $fields = match (true) {
            $model instanceof AccountInvitation => ['scope_type', 'scope_id'],
            $model instanceof VenueBooking => ['flow', 'venue_id', 'venue_court_id', 'requester_user_id', 'pre_request_id'],
            $model instanceof VenueRentalPreRequest => ['venue_id', 'venue_court_id', 'requester_user_id'],
            default => [],
        };
        if ($fields === []) {
            return;
        }
        $class = $model::class;
        $snapshots = [Arr::only($model->getAttributes(), $fields)];
        if ($includePrevious) {
            $snapshots[] = Arr::only($model->getRawOriginal(), $fields);
        }
        $model->getConnection()->afterCommit(static function () use ($class, $snapshots): void {
            try {
                $recipientIds = [];
                foreach (array_unique($snapshots, SORT_REGULAR) as $attributes) {
                    $record = new $class;
                    $record->setRawAttributes($attributes, true);
                    $recipientIds = [...$recipientIds, ...self::recipients($record)];
                }
                app(DashboardInvalidator::class)->send($recipientIds, [
                    $class === AccountInvitation::class ? 'venue_invitations' : 'venue_rental_inbox',
                ]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }

    /** @return list<int> */
    private static function recipients(Model $record): array
    {
        $invitation = $record instanceof AccountInvitation;
        if (($invitation && $record->scope_type !== 'venue') || ($record instanceof VenueBooking && $record->flow !== 'rental')) {
            return [];
        }
        $venueId = (int) ($invitation ? $record->scope_id : $record->venue_id);
        $courtId = $invitation ? 0 : (int) $record->venue_court_id;
        if ($venueId <= 0) {
            return [];
        }

        // Candidate selection is not authorization. Recheck the current
        // provider scope, including accepted/assured contracts, rollout and
        // canonical identity, before emitting any private invalidation.
        $candidates = ContractMembership::query()->where(function (Builder $q) use ($venueId, $courtId): void {
            $q->where(fn (Builder $venue) => $venue->where('scope_type', ContractMembershipScopeTypeEnum::VENUE->value)
                ->where('scope_id', $venueId));
            if ($courtId > 0) {
                $q->orWhere(fn (Builder $court) => $court->where('scope_type', ContractMembershipScopeTypeEnum::VENUE_COURT->value)
                    ->where('scope_id', $courtId));
            }
        })->select('user_id')->distinct();
        $provider = $invitation ? app(VenueInvitationsProvider::class) : app(VenueRentalInboxProvider::class);
        $ids = [];
        foreach (User::query()->whereIn('id', $candidates)->lazyById(100) as $candidate) {
            $user = $candidate->canonical();
            if ($user->isBlocked() || $user->trashed() || isset($ids[$user->id])) {
                continue;
            }
            if ($provider->canInvalidateFor($user, $record)) {
                $ids[$user->id] = (int) $user->id;
            }
        }

        return array_values($ids);
    }
}
