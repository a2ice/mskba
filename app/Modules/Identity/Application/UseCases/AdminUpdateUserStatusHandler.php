<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Exceptions\UserCannotBeChangedException;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;

final class AdminUpdateUserStatusHandler
{
    public function handle(?User $actor, int $userId, UserStatusEnum $status): User
    {
        $actor = $actor?->canonical();
        $this->assertSuperadmin($actor);

        return DB::transaction(function () use ($actor, $userId, $status): User {
            $requested = User::query()->findOrFail($userId);
            $canonicalId = (int) $requested->canonical()->id;
            $identityUsers = User::query()
                ->whereKey($canonicalId)
                ->orWhere('canonical_user_id', $canonicalId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ((int) $requested->refresh()->canonical()->id !== $canonicalId) {
                throw new UserCannotBeChangedException('Аккаунты были объединены параллельно. Повторите изменение статуса.');
            }

            if ((int) $actor->id === $canonicalId) {
                throw new UserCannotBeChangedException('Нельзя изменить статус собственного аккаунта.');
            }

            $user = $identityUsers->firstWhere('id', $canonicalId) ?? User::query()->findOrFail($canonicalId);
            $user->forceFill(['status' => $status])->save();

            return $user->refresh();
        });
    }

    private function assertSuperadmin(?User $actor): void
    {
        if (! $actor?->isConfirmed() || ! $actor->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            throw new UserCannotBeChangedException('Доступ запрещен.');
        }
    }
}
