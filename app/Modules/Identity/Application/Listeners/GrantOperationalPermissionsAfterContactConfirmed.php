<?php

namespace App\Modules\Identity\Application\Listeners;

use App\Modules\Contact\Domain\Events\UserContactConfirmed;
use App\Modules\Identity\Application\Services\VerifiedContactOperationalPermissionGranter;
use App\Modules\Identity\Domain\Models\User;

final class GrantOperationalPermissionsAfterContactConfirmed
{
    public function __construct(
        private readonly VerifiedContactOperationalPermissionGranter $granter,
    ) {}

    public function handle(UserContactConfirmed $event): void
    {
        $user = User::query()->find($event->userId);

        if ($user === null) {
            return;
        }

        $this->granter->grantMissing($user);
    }
}
