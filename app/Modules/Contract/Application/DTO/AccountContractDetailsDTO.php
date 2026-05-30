<?php

namespace App\Modules\Contract\Application\DTO;

use App\Modules\Identity\Domain\Models\User;

final readonly class AccountContractDetailsDTO
{
    public function __construct(
        public int $id,
        public ?string $number,
        public ?string $name,
        public ?string $type,
        public string $status,
        public ?string $startsAt,
        public ?string $expiresAt,
        public ?string $description,
        public string $permissions,
        public ?string $assignedBy,
        public ?User $assignedByUser,
        public array $venues,
    ) {}
}
