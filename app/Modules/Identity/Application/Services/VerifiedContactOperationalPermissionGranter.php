<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use Illuminate\Support\Facades\DB;

final class VerifiedContactOperationalPermissionGranter
{
    /**
     * Grants the contact-unlocked permissions only when their snapshots are absent.
     * An explicit administrator denial is therefore never overwritten.
     */
    public function grantMissing(User $user): void
    {
        $user = $user->canonical();

        if (! $this->hasVerifiedContact($user)) {
            return;
        }

        DB::transaction(function () use ($user): void {
            // Serialize automatic grants with admin permission updates, which also
            // lock the canonical user. This keeps an explicit admin denial stable.
            User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            foreach ($this->permissions() as $permission) {
                UserOperationalPermission::query()->firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'permission' => $permission->value,
                    ],
                    [
                        'is_allowed' => true,
                    ],
                );
            }
        });
    }

    public function hasVerifiedContact(User $user): bool
    {
        return $user->canonical()
            ->identityContactsQuery()
            ->whereNotNull('verified_at')
            ->exists();
    }

    /** @return list<UserOperationalPermissionEnum> */
    private function permissions(): array
    {
        return [
            UserOperationalPermissionEnum::CREATE_EVENT,
            UserOperationalPermissionEnum::CREATE_TOURNAMENT,
        ];
    }
}
