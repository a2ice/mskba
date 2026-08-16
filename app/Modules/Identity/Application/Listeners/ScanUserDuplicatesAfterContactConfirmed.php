<?php

namespace App\Modules\Identity\Application\Listeners;

use App\Modules\Contact\Domain\Events\UserContactConfirmed;
use App\Modules\Identity\Application\Services\UserDuplicateDetector;
use App\Modules\Identity\Domain\Models\User;

final class ScanUserDuplicatesAfterContactConfirmed
{
    public function __construct(
        private readonly UserDuplicateDetector $detector,
    ) {}

    public function handle(UserContactConfirmed $event): void
    {
        $user = User::query()->find($event->userId);

        if ($user !== null) {
            $this->detector->scan($user);
        }
    }
}
