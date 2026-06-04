<?php

namespace App\Modules\Contact\Domain\Events;

final readonly class UserContactConfirmed
{
    public function __construct(
        public int $userId,
        public int $contactId,
    ) {}
}
