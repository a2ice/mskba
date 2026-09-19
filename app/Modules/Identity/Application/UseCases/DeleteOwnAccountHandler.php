<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteOwnAccountHandler
{
    public function handle(User $user): void
    {
        $canonical = $user->canonical();
        $identityIds = $canonical->identityIds();

        DB::transaction(function () use ($identityIds): void {
            $users = User::query()
                ->whereKey($identityIds)
                ->lockForUpdate()
                ->get();

            $users->each->delete();
        });
    }
}
