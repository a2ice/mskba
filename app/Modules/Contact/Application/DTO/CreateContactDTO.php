<?php

namespace App\Modules\Contact\Application\DTO;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;

final readonly class CreateContactDTO
{
    public function __construct(
        public ContactTypeEnum $type,
        public string $value,
        public ?string $label = null,
        public bool $isPrimary = false,
    ) {}
}
