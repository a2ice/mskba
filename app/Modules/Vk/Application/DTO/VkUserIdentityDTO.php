<?php

namespace App\Modules\Vk\Application\DTO;

final readonly class VkUserIdentityDTO
{
    /** @param array<string, mixed> $rawData */
    public function __construct(
        public string $id,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $avatarUrl,
        public array $rawData,
    ) {}
}
