<?php

namespace App\Modules\Identity\Application\DTO;

final readonly class LoginResponseDTO
{
    public function __construct(
        public string $status,
        public string $message,
        public int $httpStatus,
    ) {}
}
