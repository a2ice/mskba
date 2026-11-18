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
        $canonicalId = (int) $canonical->id;

        DB::transaction(function () use ($identityIds, $canonicalId): void {
            $users = User::query()
                ->whereKey($identityIds)
                ->lockForUpdate()
                ->get();

            $users->each(function (User $account) use ($canonicalId): void {
                if ((int) $account->id === $canonicalId) {
                    // Only this self-service flow sets the restoration eligibility marker.
                    $account->forceFill(['self_deleted_at' => now()])->save();
                }
                $account->tokens()->delete();
                $account->delete();
            });
        });
    }
}
