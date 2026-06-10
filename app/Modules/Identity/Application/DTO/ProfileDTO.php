<?php

namespace App\Modules\Identity\Application\DTO;

use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use Carbon\CarbonImmutable;

final readonly class ProfileDTO
{
    public function __construct(
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $middleName = null,
        public ?UserGenderEnum $gender = null,
        public ?CarbonImmutable $birthDate = null,
    ) {}
}
