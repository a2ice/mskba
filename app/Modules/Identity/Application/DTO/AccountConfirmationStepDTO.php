<?php

namespace App\Modules\Identity\Application\DTO;

final readonly class AccountConfirmationStepDTO
{
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public bool $required,
        public bool $completed,
    ) {}

    public function marker(): string
    {
        return $this->required ? 'О' : 'Н';
    }
}
