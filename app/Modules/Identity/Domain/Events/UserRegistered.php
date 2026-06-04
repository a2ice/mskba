<?php

namespace App\Modules\Identity\Domain\Events;

final readonly class UserRegistered
{
    public function __construct(
        public int $userId,
    ) {}
}
