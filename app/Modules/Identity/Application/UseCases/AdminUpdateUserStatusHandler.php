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

            if ((int) $actor->id === $canonicalId) {
                throw new UserCannotBeChangedException('Нельзя изменить статус собственного аккаунта.');
            }

            $user = User::query()->whereKey($canonicalId)->lockForUpdate()->firstOrFail();
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
