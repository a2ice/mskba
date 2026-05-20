<?php

namespace App\Modules\Identity\Application\DTO;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;

class UserQueryFiltersDTO
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 10,
        public ?string $sortBy = 'id',
        public string $sortDirection = 'asc',
        public bool $includeProfile = false,
        public bool $includeContacts = false,
        public bool $includeParticipationRoles = false,
        public ?UserStatusEnum $status = null,
        public ?UserSystemRoleEnum $systemRole = null,
    ) {}
}