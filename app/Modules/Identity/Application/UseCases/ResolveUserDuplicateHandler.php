<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Application\Services\UserDuplicateSelfServiceProofStore;
use App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserDuplicate;
use App\Modules\Reaction\Application\Services\CanonicalReactionConsolidator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ResolveUserDuplicateHandler
{
    public function __construct(
        private readonly UserDuplicateSelfServiceProofStore $selfServiceProofs,
        private readonly CanonicalReactionConsolidator $reactionConsolidator,
    ) {}

    public function reject(UserDuplicate $candidate, ?User $resolvedBy, ?string $reason = null): void
    {
        DB::transaction(function () use ($candidate, $resolvedBy, $reason): void {
            $candidate = UserDuplicate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $this->assertAdminResolutionAllowed($resolvedBy);

            if ($candidate->status === UserDuplicateStatusEnum::MERGED) {
                throw new InvalidArgumentException('Уже объединённую пару нельзя отклонить.');
            }

            $candidate->forceFill([
                'status' => UserDuplicateStatusEnum::REJECTED,
                'resolved_evidence_hash' => $candidate->evidence_hash,
                'resolved_by' => $resolvedBy?->canonical()->id,
                'resolved_at' => now(),
                'metadata' => array_replace($candidate->metadata ?? [], [
                    'resolution' => 'rejected',
                    'resolution_reason' => $reason,
                ]),
            ])->save();
        });
    }

    public function merge(
        UserDuplicate $candidate,
        int $canonicalUserId,
        ?User $resolvedBy,
        bool $selfService = false,
        ?string $selfServiceSessionId = null,
    ): User {
        return DB::transaction(function () use (
            $candidate,
            $canonicalUserId,
            $resolvedBy,
            $selfService,
            $selfServiceSessionId,
        ): User {
            $candidate = UserDuplicate::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $selfService) {
                $this->assertAdminResolutionAllowed($resolvedBy);
            }

            if ($candidate->status !== UserDuplicateStatusEnum::PENDING) {
                throw new InvalidArgumentException('Объединить можно только активного кандидата на дублирование.');
            }

            if (! $candidate->evidence()->where('is_active', true)->exists()) {
                throw new InvalidArgumentException('У пары больше нет актуальных подтверждений дублирования.');
            }

            $pairIds = [(int) $candidate->user_id, (int) $candidate->duplicate_user_id];

            if (! in_array($canonicalUserId, $pairIds, true)) {
                throw new InvalidArgumentException('Канонический пользователь должен входить в пару кандидата.');
            }

            $pairUsers = User::withTrashed()->whereIn('id', $pairIds)->get();
            if ($pairUsers->count() !== 2) {
                throw new InvalidArgumentException('Один из пользователей кандидата не найден.');
            }
            $identityRootIds = $pairUsers
                ->map(fn (User $user): int => (int) $user->canonical()->id)
                ->unique()
                ->values();
            $identityUsers = User::withTrashed()
                ->where(function ($query) use ($identityRootIds): void {
                    $query
                        ->whereIn('id', $identityRootIds)
                        ->orWhereIn('canonical_user_id', $identityRootIds);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var User $requestedCanonical */
            $requestedCanonical = $identityUsers->get($canonicalUserId);
            /** @var User $other */
            $other = $identityUsers->get($pairIds[0] === $canonicalUserId ? $pairIds[1] : $pairIds[0]);

            if ($requestedCanonical === null || $other === null) {
                throw new InvalidArgumentException('Пара кандидата изменилась. Запустите проверку дублей повторно.');
            }

            if ($requestedCanonical->trashed() || $other->trashed()) {
                throw new InvalidArgumentException('Удалённые аккаунты нельзя объединять через механизм дублей.');
            }

            $canonical = $requestedCanonical->canonical();
            $sourceCanonical = $other->canonical();

            $currentRootIds = collect([$canonical->id, $sourceCanonical->id])->sort()->values();
            if ($currentRootIds->all() !== $identityRootIds->sort()->values()->all()) {
                throw new InvalidArgumentException('Пара кандидата была изменена параллельным объединением. Повторите операцию.');
            }

            if ($canonical->id === $sourceCanonical->id) {
                throw new InvalidArgumentException('Эти аккаунты уже относятся к одной identity.');
            }

            $this->assertRoleMergeAllowed($canonical, $sourceCanonical, $selfService);
            $this->assertStatusMergeAllowed($canonical, $sourceCanonical, $selfService);

            if ($selfService) {
                if (! $this->canSelfResolve($candidate, $resolvedBy)) {
                    throw new InvalidArgumentException('Недостаточно подтверждений для самостоятельного объединения аккаунтов.');
                }

                if (
                    $resolvedBy === null
                    || $selfServiceSessionId === null
                    || ! $this->selfServiceProofs->consume($candidate, $resolvedBy, $selfServiceSessionId)
                ) {
                    throw new InvalidArgumentException('Подтверждение Telegram устарело. Повторно подтвердите Telegram перед объединением аккаунтов.');
                }
            }

            User::query()
                ->where('canonical_user_id', $sourceCanonical->id)
                ->update(['canonical_user_id' => $canonical->id]);

            $sourceCanonical->forceFill([
                'canonical_user_id' => $canonical->id,
            ])->save();

            $canonical->forceFill([
                'canonical_user_id' => null,
            ])->save();

            $this->reactionConsolidator->consolidate($canonical);

            $candidate->forceFill([
                'status' => UserDuplicateStatusEnum::MERGED,
                'resolved_evidence_hash' => $candidate->evidence_hash,
                'resolved_by' => $resolvedBy?->canonical()->id,
                'resolved_at' => now(),
                'metadata' => array_replace($candidate->metadata ?? [], [
                    'resolution' => $selfService ? 'self_service_merge' : 'admin_merge',
                    'canonical_user_id' => (int) $canonical->id,
                ]),
            ])->save();

            return $canonical->refresh();
        });
    }

    private function canSelfResolve(UserDuplicate $candidate, ?User $resolvedBy): bool
    {
        if ($resolvedBy === null) {
            return false;
        }

        $resolvedCanonicalId = (int) $resolvedBy->canonical()->id;
        $pairIds = [(int) $candidate->user_id, (int) $candidate->duplicate_user_id];

        if (! in_array($resolvedCanonicalId, $pairIds, true)) {
            return false;
        }

        return $candidate->evidence()
            ->where('is_active', true)
            ->where('type', 'telegram_identity')
            ->get()
            ->contains(function ($evidence) use ($resolvedCanonicalId): bool {
                return (int) ($evidence->metadata['self_service_user_id'] ?? 0) === $resolvedCanonicalId
                    && ($evidence->metadata['source'] ?? null) === 'signed_telegram_auth';
            });
    }

    private function assertAdminResolutionAllowed(?User $resolvedBy): void
    {
        $actor = $resolvedBy?->canonical();

        if (
            $actor === null
            || $actor->trashed()
            || $actor->status !== UserStatusEnum::CONFIRMED
            || $actor->system_role !== UserSystemRoleEnum::SUPERADMIN
        ) {
            throw new InvalidArgumentException('Разрешать или отклонять дубли пользователей может только подтверждённый суперадминистратор.');
        }
    }

    private function assertRoleMergeAllowed(User $canonical, User $source, bool $selfService): void
    {
        $protectedRoles = [UserSystemRoleEnum::SUPERADMIN, UserSystemRoleEnum::SYSTEM];

        if (
            in_array($canonical->system_role, $protectedRoles, true)
            || in_array($source->system_role, $protectedRoles, true)
        ) {
            throw new InvalidArgumentException('Аккаунты суперадминистратора и системного пользователя нельзя объединять через механизм дублей.');
        }

        if (
            $selfService
            && (
                $canonical->system_role !== UserSystemRoleEnum::USER
                || $source->system_role !== UserSystemRoleEnum::USER
            )
        ) {
            throw new InvalidArgumentException('Аккаунт с расширенными системными правами может объединить только суперадминистратор после ручной проверки.');
        }
    }

    private function assertStatusMergeAllowed(User $canonical, User $source, bool $selfService): void
    {
        $hasBlockedAccount = $canonical->status === UserStatusEnum::BLOCKED
            || $source->status === UserStatusEnum::BLOCKED;

        if (! $hasBlockedAccount) {
            return;
        }

        if ($selfService) {
            throw new InvalidArgumentException('Заблокированный аккаунт нельзя объединить самостоятельно. Обратитесь к администратору.');
        }

        if ($canonical->status !== UserStatusEnum::BLOCKED) {
            throw new InvalidArgumentException('При объединении с заблокированным аккаунтом заблокированный аккаунт должен остаться основным, чтобы не снять ограничение через merge.');
        }
    }
}
