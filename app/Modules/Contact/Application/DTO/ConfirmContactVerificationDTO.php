<?php

namespace App\Modules\Contact\Application\DTO;

final readonly class ConfirmContactVerificationDTO
{
    public function __construct(
        public string $code,
    ) {}
}
