<?php

namespace App\Modules\Contract\Application\Services;

use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractVerification;
use Illuminate\Database\Eloquent\Builder;

final class ContractAssurance
{
    public function allows(Contract $contract): bool
    {
        if ($contract->verification_requirement === 'none') {
            return true;
        }

        return $contract->verification?->isValid() ?? false;
    }

    /** Only evidence whose approval is still valid unlocks a verified contract. */
    public function constrain(Builder $contracts): Builder
    {
        return $contracts->where(fn (Builder $q) => $q
            ->where('verification_requirement', 'none')
            ->orWhereHas('verification', fn (Builder $v) => $v
                ->where('status', 'verified')
                ->whereNotNull('verified_at')
                ->where('verified_at', '<=', now())
                ->where(fn (Builder $dates) => $dates
                    ->whereNull('expires_at')->orWhere('expires_at', '>', now()))));
    }

    /** @param array<string, mixed> $attributes */
    public function recordVerified(Contract $contract, string $sourceType, int $sourceId, int $reviewerId, array $attributes = []): ContractVerification
    {
        return $contract->verification()->updateOrCreate([], [
            'status' => 'verified',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reviewer_user_id' => $reviewerId,
            'verified_at' => now(),
            'expires_at' => $attributes['expires_at'] ?? null,
            'decision_reason' => $attributes['decision_reason'] ?? null,
        ]);
    }
}
