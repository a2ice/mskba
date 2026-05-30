<?php

namespace App\Modules\Contract\Application\DTO;

final readonly class AccountContractListItemDTO
{
    public function __construct(
        public int $id,
        public ?string $number,
        public string $status,
        public ?string $startsAt,
        public ?string $expiresAt,
        public array $venues,
    ) {}
}
