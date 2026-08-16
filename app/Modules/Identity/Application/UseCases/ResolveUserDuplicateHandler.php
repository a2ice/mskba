<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserDuplicate;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ResolveUserDuplicateHandler
{
    public function reject(UserDuplicate $candidate, ?User $resolvedBy, ?string $reason = null): void
    {
        DB::transaction(function () use ($candidate, $resolvedBy, $reason): void {
            $candidate = UserDuplicate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            if ($candidate->status === UserDuplicateStatusEnum::MERGED) {
                throw new InvalidArgumentException('Уже объединённую пару нельзя отклонить.');
            }

            $candidate->forceFill([
                'status' => UserDuplicateStatusEnum::REJECTED,
                'resolved_evidence_hash' => $candidate->evidence_hash,
                'resolved_by' => $resolvedBy?->id,
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
    ): User {
        return DB::transaction(function () use ($candidate, $canonicalUserId, $resolvedBy, $selfService): User {
            $candidate = UserDuplicate::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            $pairIds = [(int) $candidate->user_id, (int) $candidate->duplicate_user_id];

            if (! in_array($canonicalUserId, $pairIds, true)) {
                throw new InvalidArgumentException('Канонический пользователь должен входить в пару кандидата.');
            }

            $users = User::withTrashed()
                ->whereIn('id', $pairIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($users->count() !== 2) {
                throw new InvalidArgumentException('Один из пользователей кандидата не найден.');
            }

            /** @var User $requestedCanonical */
            $requestedCanonical = $users->get($canonicalUserId);
            /** @var User $other */
            $other = $users->get($pairIds[0] === $canonicalUserId ? $pairIds[1] : $pairIds[0]);

            $canonical = $requestedCanonical->canonical();
            $sourceCanonical = $other->canonical();

            if ($canonical->id === $sourceCanonical->id) {
                $candidate->forceFill([
                    'status' => UserDuplicateStatusEnum::MERGED,
                    'resolved_evidence_hash' => $candidate->evidence_hash,
                    'resolved_by' => $resolvedBy?->id,
                    'resolved_at' => now(),
                ])->save();

                return $canonical;
            }

            if ($selfService && ! $this->canSelfResolve($candidate, $resolvedBy)) {
                throw new InvalidArgumentException('Недостаточно подтверждений для самостоятельного объединения аккаунтов.');
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

            $candidate->forceFill([
                'status' => UserDuplicateStatusEnum::MERGED,
                'resolved_evidence_hash' => $candidate->evidence_hash,
                'resolved_by' => $resolvedBy?->id,
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
}
