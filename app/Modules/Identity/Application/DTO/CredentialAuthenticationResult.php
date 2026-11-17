<?php

namespace App\Modules\Identity\Application\DTO;

use App\Modules\Identity\Domain\Models\User;

final readonly class CredentialAuthenticationResult
{
    public function __construct(
        public ?User $user,
        public string $status,
        public string $message,
        public int $httpStatus,
        public bool $temporaryPassword = false,
    ) {}

    public function successful(): bool
    {
        return $this->user !== null;
    }
}
