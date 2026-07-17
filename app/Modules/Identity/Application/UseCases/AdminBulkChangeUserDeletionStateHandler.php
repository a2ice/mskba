<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Exceptions\UserCannotBeChangedException;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;

final class AdminBulkChangeUserDeletionStateHandler
{
    /** @param array<int> $userIds */
    public function delete(?User $actor, array $userIds): int
    {
        $this->assertSuperadmin($actor);
        $this->assertDoesNotContainActor($actor, $userIds);

        return DB::transaction(function () use ($userIds): int {
            $users = User::query()->whereKey($userIds)->lockForUpdate()->get();
            $users->each->delete();

            return $users->count();
        });
    }

    /** @param array<int> $userIds */
    public function restore(?User $actor, array $userIds): int
    {
        $this->assertSuperadmin($actor);

        return DB::transaction(function () use ($userIds): int {
            $users = User::query()->onlyTrashed()->whereKey($userIds)->lockForUpdate()->get();
            $users->each->restore();

            return $users->count();
        });
    }

    private function assertSuperadmin(?User $actor): void
    {
        if (! $actor?->isConfirmed() || ! $actor->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            throw new UserCannotBeChangedException('Доступ запрещен.');
        }
    }

    /** @param array<int> $userIds */
    private function assertDoesNotContainActor(User $actor, array $userIds): void
    {
        if (in_array($actor->id, $userIds, true)) {
            throw new UserCannotBeChangedException('Нельзя удалить собственный аккаунт.');
        }
    }
}
