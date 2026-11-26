<?php

namespace App\Modules\Dashboard\Infrastructure\Observers;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Contract\Domain\Models\ContractPermission;
use App\Modules\Dashboard\Application\Services\DashboardInvalidator;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use Illuminate\Database\Eloquent\Model;

final class DashboardAccessObserver
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
        // Membership/permission removals retain their owner attributes.
        // Contract deletions with DB cascades are covered by fallback.
        $this->schedule($model, false);
    }

    private function schedule(Model $model, bool $previous): void
    {
        try {
            $ids = match (true) {
                $model instanceof UserOperationalPermission => [$model->user_id, $previous ? $model->getRawOriginal('user_id') : null],
                $model instanceof ContractMembership => $this->membershipIds($model, $previous),
                $model instanceof Contract, $model instanceof ContractPermission => ContractMembership::query()
                    ->whereIn('contract_id', $model instanceof Contract ? [$model->id] :
                        array_filter([$model->contract_id, $previous ? $model->getRawOriginal('contract_id') : null]))
                    ->whereIn('scope_type', $this->venueScopes())->pluck('user_id')->all(),
                default => [],
            };
            $ids = array_values(array_unique(array_filter($ids)));
            if ($ids === []) {
                return;
            }

            // Do not filter by the new permissions: former holders must
            // receive a catalogue refresh to remove a revoked card.
            $model->getConnection()->afterCommit(static function () use ($ids): void {
                try {
                    app(DashboardInvalidator::class)->send($ids, ['venue_rental_inbox', 'venue_invitations'], true);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function venueScopes(): array
    {
        return [ContractMembershipScopeTypeEnum::VENUE->value, ContractMembershipScopeTypeEnum::VENUE_COURT->value];
    }

    private function membershipIds(ContractMembership $model, bool $previous): array
    {
        $ids = [];
        if (in_array($model->scope_type->value, $this->venueScopes(), true)) {
            $ids[] = $model->user_id;
        }
        if ($previous && in_array($model->getRawOriginal('scope_type'), $this->venueScopes(), true)) {
            $ids[] = $model->getRawOriginal('user_id');
        }

        return $ids;
    }
}
