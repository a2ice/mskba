<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Contract\Domain\Models\ContractRelation;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnership;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Role resolution is deliberately separate from marking a pair as duplicate.
 * Historical (inactive) contracts remain attached to their original venue.
 * Active responsibilities require a superadmin's explicit choice.
 */
final class VenueDuplicateContractRights
{
    public const KEEP = 'keep';
    public const TRANSFER = 'transfer';
    public const RETIRE = 'retire';

    public function summary(int $canonicalId, int $duplicateId): string
    {
        if ($canonicalId <= 0 || $duplicateId <= 0 || $canonicalId === $duplicateId) {
            return 'Сначала выбери основную площадку.';
        }

        $memberships = $this->memberships($duplicateId);
        $active = $memberships->filter(fn (ContractMembership $m): bool =>
            $m->contract?->status === ContractStatusEnum::ACTIVE);
        $main = $this->memberships($canonicalId)->filter(fn (ContractMembership $m): bool =>
            $m->contract?->status === ContractStatusEnum::ACTIVE);
        $roles = $active->map(fn (ContractMembership $m): string => '#'.$m->user_id.' / '.$m->access_level)
            ->implode(', ');

        return 'Права дубля #'.$duplicateId.': активных — '.$active->count()
            .', исторических — '.($memberships->count() - $active->count())
            .'. Основная #'.$canonicalId.': активных — '.$main->count().'.'
            .($roles !== '' ? ' На дубле: '.$roles.'.' : '')
            .' Выбор разрешения прав обязателен для активных договоров.';
    }

    /** @return list<int> IDs of modified active contracts or memberships */
    public function resolve(Venue $canonical, Venue $duplicate, string $strategy, ?User $admin): array
    {
        if (! in_array($strategy, [self::KEEP, self::TRANSFER, self::RETIRE], true)) {
            throw new InvalidArgumentException('Неизвестный способ обработки договорных прав.');
        }

        $all = $this->memberships($duplicate->id);
        $active = $all->filter(fn (ContractMembership $m): bool =>
            $m->contract?->status === ContractStatusEnum::ACTIVE)->values();

        if ($active->isEmpty()) {
            // Past contracts are historical evidence, not an operational blocker.
            return [];
        }

        if ($strategy === self::KEEP) {
            throw new InvalidArgumentException(
                'На дубле #'.$duplicate->id.' обнаружены активные договорные права. '
                .'Выбери в объединении способ обработки прав; исторические записи сохраняются.'
            );
        }

        if ($admin?->system_role !== UserSystemRoleEnum::SUPERADMIN
            || ! $admin->isConfirmed() || $admin->isBlocked() || $admin->trashed()) {
            throw new InvalidArgumentException('Перенос или завершение договорных прав доступно только суперадминистратору.');
        }

        // Property ownership is a separately verified legal workflow, not a
        // catalogue-role migration. Never silently transfer or revoke it.
        if (VenueOwnership::query()->where('venue_id', $duplicate->id)->exists()
            || $active->contains(fn (ContractMembership $m): bool => $m->access_level === 'owner')) {
            throw new InvalidArgumentException(
                'На дубле #'.$duplicate->id.' есть права подтверждённого владельца. '
                .'Сначала урегулируй владение отдельной процедурой.'
            );
        }

        $activeIds = $active->pluck('contract_id')->all();
        if (ContractRelation::query()->whereIn('contract_id', $activeIds)->exists()) {
            throw new InvalidArgumentException(
                'Договоры дубля #'.$duplicate->id.' имеют связанные правоотношения. '
                .'Они требуют отдельной проверки перед переносом или завершением.'
            );
        }

        if ($strategy === self::TRANSFER) {
            $onCanonical = $this->memberships($canonical->id)
                ->filter(fn (ContractMembership $m): bool => $m->contract?->status === ContractStatusEnum::ACTIVE);
            foreach ($active as $membership) {
                $conflict = $onCanonical->first(fn (ContractMembership $other): bool =>
                    ($membership->access_level === 'primary_manager'
                        && $other->access_level === 'primary_manager')
                    || ($other->user_id === $membership->user_id
                        && $other->access_level === $membership->access_level));
                if ($conflict !== null) {
                    throw new InvalidArgumentException(
                        'На основной площадке #'.$canonical->id.' уже есть пересекающаяся активная роль '
                        .$membership->access_level.' (договор #'.$conflict->contract_id.'). '
                        .'Нельзя перенести её автоматически; проверь права или явно выбери сохранение прав основной.'
                    );
                }
            }
        }

        $modified = [];
        foreach ($active as $membership) {
            // Lock contract during the same outer merge transaction.
            $contract = Contract::query()->whereKey($membership->contract_id)->lockForUpdate()->firstOrFail();
            if ($contract->status !== ContractStatusEnum::ACTIVE) {
                throw new InvalidArgumentException('Договор #'.$contract->id.' изменился во время объединения.');
            }

            if ($strategy === self::TRANSFER) {
                $membership->forceFill(['scope_id' => $canonical->id])->save();
            } else {
                // Preserve the original contract and membership for history;
                // explicitly end its authority instead of granting it to #11.
                $contract->forceFill([
                    'status' => ContractStatusEnum::INACTIVE,
                    'expires_at' => now(),
                    'comment' => trim(($contract->comment ? $contract->comment.' ' : '')
                        .'Завершён при объединении дубля #'.$duplicate->id
                        .' с основной площадкой #'.$canonical->id
                        .' суперадминистратором #'.$admin->id.'.'),
                ])->save();
            }
            $modified[] = (int) $membership->id;
        }

        return $modified;
    }

    /** @return Collection<int, ContractMembership> */
    private function memberships(int $venueId): Collection
    {
        return ContractMembership::query()
            ->with('contract')
            ->where('scope_type', ContractMembershipScopeTypeEnum::VENUE->value)
            ->where('scope_id', $venueId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
