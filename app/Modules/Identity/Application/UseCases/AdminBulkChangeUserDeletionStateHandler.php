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
        $actor = $actor?->canonical();
        $this->assertSuperadmin($actor);

        return DB::transaction(function () use ($actor, $userIds): int {
            $users = User::query()->whereKey($userIds)->lockForUpdate()->get();

            if ($users->count() !== count(array_unique(array_map('intval', $userIds)))) {
                throw new UserCannotBeChangedException('Один из выбранных аккаунтов не найден.');
            }

            foreach ($users as $user) {
                $canonical = $user->canonical();

                if ((int) $canonical->id === (int) $actor->id) {
                    throw new UserCannotBeChangedException('Нельзя удалить собственный аккаунт или его alias.');
                }

                if ($user->canonical_user_id !== null || $user->aliases()->exists()) {
                    throw new UserCannotBeChangedException(
                        'Аккаунты, участвующие в canonical identity, нельзя удалять через обычную админку. Сначала требуется отдельное безопасное разъединение identity.',
                    );
                }
            }

            $users->each->delete();

            return $users->count();
        });
    }

    /** @param array<int> $userIds */
    public function restore(?User $actor, array $userIds): int
    {
        $actor = $actor?->canonical();
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
}
