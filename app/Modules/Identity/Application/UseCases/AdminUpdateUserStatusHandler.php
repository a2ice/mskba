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
        $this->assertSuperadmin($actor);

        if ($actor->id === $userId) {
            throw new UserCannotBeChangedException('Нельзя изменить статус собственного аккаунта.');
        }

        return DB::transaction(function () use ($userId, $status): User {
            $user = User::query()->lockForUpdate()->findOrFail($userId);

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
