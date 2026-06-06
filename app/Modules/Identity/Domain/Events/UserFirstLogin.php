<?php

namespace App\Modules\Identity\Domain\Events;

final readonly class UserFirstLogin
{
    public function __construct(
        public int $userId,
    ) {}
}
