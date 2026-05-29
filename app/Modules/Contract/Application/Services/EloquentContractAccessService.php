<?php

namespace App\Modules\Contract\Application\Services;

use App\Modules\Contract\Application\Contracts\ContractAccessInterface;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class EloquentContractAccessService implements ContractAccessInterface
{
    public function allows(User $user, string $subjectType, int $subjectId, string $permission): bool
    {
        return $this->baseQuery($user, $subjectType, $permission)
            ->where('subject_id', $subjectId)
            ->exists();
    }

    /**
     * @return array<int>
     */
    public function allowedSubjectIds(User $user, string $subjectType, string $permission): array
    {
        return $this->baseQuery($user, $subjectType, $permission)
            ->pluck('subject_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function baseQuery(User $user, string $subjectType, string $permission): Builder
    {
        $now = now();

        return Contract::query()
            ->where('user_id', $user->id)
            ->where('subject_type', $subjectType)
            ->where('permission', $permission)
            ->where('status', ContractStatusEnum::ACTIVE->value)
            ->where(function ($query) use ($now): void {
                $query
                    ->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            });
    }
}
