<?php

namespace App\Modules\Identity\Domain\Events;

final readonly class UserAccountConfirmed
{
    public function __construct(
        public int $userId,
    ) {}
}
